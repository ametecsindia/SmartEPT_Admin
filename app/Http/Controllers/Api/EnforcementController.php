<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPolicy;
use App\Models\EmployeeDevice;
use App\Models\EnforcementAuditEvent;
use App\Models\EnforcementMachine;
use App\Models\EnforcementState;
use App\Models\PolicyAssignment;
use App\Models\PolicyRule;
use App\Models\WebsitePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The audit gate.
 *
 * Enforcement is never switched straight on. A tenant runs in AUDIT, every
 * endpoint reports what it WOULD have blocked, and only a report with nothing
 * unexpected in it can promote the tenant to ENFORCE.
 *
 * That is not bureaucracy. The strict allow set covers %WINDIR% and
 * %PROGRAMFILES% only, so the whole user profile is denied — which kills
 * portable browsers for free and also kills Teams, VS Code and every other
 * application the client installed into %LOCALAPPDATA%. The audit period is how
 * we find those from an event log instead of from an angry phone call.
 *
 * Two audiences:
 *   agent/enforcer  POST audit-event      report a would-have-blocked
 *   console         GET  audit-report     read it
 *                   POST promote          AUDIT -> ENFORCE, gated on the report
 *                   POST disable          the kill switch, never gated
 */
class EnforcementController extends Controller
{
    /**
     * The enforcement service reports something its policy stopped, or would
     * have stopped. Called by the endpoint, not by a human.
     *
     * Endpoints report the same program many times a day, so rows are collapsed
     * per (company, device, target) with a counter rather than stored one by one.
     */
    public function storeAuditEvent(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('agent'), 403, 'Agent token required.');

        $data = $request->validate([
            'device_uuid' => ['required', 'string'],
            'events'      => ['required', 'array', 'min:1', 'max:500'],
            'events.*.target'    => ['required', 'string', 'max:512'],
            'events.*.outcome'   => ['nullable', 'in:WOULD_BLOCK,BLOCKED,ALLOWED_BY_RULE'],
            'events.*.source'    => ['nullable', 'in:APPLOCKER,FIREWALL,PROCESS'],
            'events.*.rule_name' => ['nullable', 'string', 'max:191'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.occurrences' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $device = EmployeeDevice::where('device_uuid', $data['device_uuid'])->firstOrFail();
        $employee = $device->employee;
        abort_unless($employee, 422, 'Device is not bound to an employee.');

        // What this tenant MEANT to block. Anything else in the report is a
        // program the client actually uses, and is what blocks promotion.
        $expected = $this->expectedTargets($employee->company_id);

        $stored = 0;
        foreach ($data['events'] as $e) {
            $target = trim((string) $e['target']);
            if ($target === '') {
                continue;
            }
            $seenAt = isset($e['occurred_at']) ? \Illuminate\Support\Carbon::parse($e['occurred_at']) : now();
            $hash   = hash('sha256', mb_strtolower($target));

            $row = EnforcementAuditEvent::withoutGlobalScopes()->firstOrNew([
                'company_id'  => $employee->company_id,
                'device_uuid' => $device->device_uuid,
                'target_hash' => $hash,
            ]);

            $row->employee_id   = $employee->id;
            $row->target        = $target;
            $row->target_hash   = $hash;
            $row->source        = $e['source'] ?? 'APPLOCKER';
            $row->outcome       = $e['outcome'] ?? 'WOULD_BLOCK';
            $row->rule_name     = $e['rule_name'] ?? $row->rule_name;
            $row->expected      = $this->isExpected($target, $expected);
            $row->occurrences   = (int) ($row->occurrences ?? 0) + (int) ($e['occurrences'] ?? 1);
            $row->first_seen_at = $row->first_seen_at ?: $seenAt;
            $row->last_seen_at  = $seenAt;
            $row->save();

            $stored++;
        }

        $device->forceFill(['enforcement_reported_at' => now()])->save();

        return response()->json(['ok' => true, 'stored' => $stored], 201);
    }

    /**
     * The report the promotion is gated on, and most of the Compliance
     * Attestation an auditor asks for.
     */
    public function auditReport(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $state = EnforcementState::forCompany($companyId);

        $rows = EnforcementAuditEvent::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('occurrences')
            ->get();

        $intended   = $rows->where('expected', true)->values();
        $unexpected = $rows->where('expected', false)->whereNull('resolved_at')->values();

        return response()->json([
            'ok'   => true,
            'data' => [
                // What the console must draw. On an installation with no learning period a
                // stored AUDIT reads OFF — there is no third state to show, and "learning"
                // would be a stage the admin can neither enter nor leave.
                'mode'             => $state->effectiveMode(),
                'stored_mode'      => $state->mode,
                'learning_enabled' => EnforcementState::learningEnabled(),
                'audit_started_at' => $state->audit_started_at?->toIso8601String(),
                'audit_days'       => $state->auditDays(),
                'audit_minutes'    => $state->auditMinutes(),
                'min_audit_minutes' => EnforcementState::minAuditMinutes(),
                // USB / removable-storage block (company-wide device control).
                'block_removable_storage' => (bool) \App\Models\Company::withoutGlobalScopes()->whereKey($companyId)->value('block_removable_storage'),
                'block_camera_device'     => (bool) \App\Models\Company::withoutGlobalScopes()->whereKey($companyId)->value('block_camera_device'),
                'block_browser_uploads'   => (bool) \App\Models\Company::withoutGlobalScopes()->whereKey($companyId)->value('block_browser_uploads'),
                // Media Control: video blocking via the SmartEPT Media Control
                // browser extension. Same company-wide switch shape as the rest
                // of this card — see EnforcerSyncController::mediaControlFor().
                'block_media_streaming'   => (bool) \App\Models\Company::withoutGlobalScopes()->whereKey($companyId)->value('block_media_streaming'),
                // THREE different numbers, because they mean three different things and the
                // console was conflating them (Ejaz, 27-Aug-2026: "one agent had already
                // logged in to other PC, but the Enforcement section says 0 PCs").
                //
                //   enrolled   an enforcement service exists on that PC and has a credential
                //   live       ...and it has checked in recently
                //   reporting  ...and it has actually stopped something at least once
                //
                // Only the last one was shown, labelled "PC(s) reporting". So a site with no
                // enforcement service anywhere and a site whose service is working perfectly
                // but has had nothing to block both read "0 PC(s) reporting" — and those need
                // completely different actions. Worse, the employee agent signing in changes
                // none of these numbers, which is exactly the confusion that was reported.
                'machines_enrolled' => EnforcementMachine::withoutGlobalScopes()
                    ->where('company_id', $companyId)->whereNull('revoked_at')->count(),
                'machines_live'     => EnforcementMachine::withoutGlobalScopes()
                    ->where('company_id', $companyId)->whereNull('revoked_at')
                    ->where('last_seen_at', '>=', now()->subMinutes(10))->count(),
                'devices_reporting' => $rows->pluck('device_uuid')->filter()->unique()->count(),
                'intended'         => $intended,
                'unexpected'       => $unexpected,
                // Everything that has to be true before this tenant may enforce.
                'promotion'        => $this->promotionCheck($state, $unexpected->count(), $rows->count()),
            ],
        ]);
    }

    /**
     * Start the learning period. Nothing is blocked; endpoints begin reporting.
     */
    public function startAudit(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseWhenLearningRemoved()) {
            return $refusal;
        }

        $state = EnforcementState::forCompany((int) $request->user()->company_id);
        $state->fill([
            'mode'             => EnforcementState::AUDIT,
            'audit_started_at' => now(),
            'policy_version'   => (int) $state->policy_version + 1,
            'disabled_at'      => null,
            'disabled_reason'  => null,
        ])->save();

        return response()->json(['ok' => true, 'data' => $state]);
    }

    /**
     * AUDIT -> ENFORCE. Refused unless the report is clean.
     *
     * There is no override parameter. If a client needs an unexpected program to
     * keep working, the answer is to allow that program, not to skip the check.
     */
    public function promote(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseWhenLearningRemoved()) {
            return $refusal;
        }

        $companyId = (int) $request->user()->company_id;
        $state = EnforcementState::forCompany($companyId);

        $unexpected = EnforcementAuditEvent::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('expected', false)
            ->whereNull('resolved_at')
            ->count();

        $total = EnforcementAuditEvent::withoutGlobalScopes()->where('company_id', $companyId)->count();
        $check = $this->promotionCheck($state, $unexpected, $total);

        if (! $check['allowed']) {
            return response()->json([
                'ok'      => false,
                'message' => $check['reason'],
                'data'    => $check,
            ], 422);
        }

        $reportId = 'R-' . $companyId . '-' . now()->format('Ymd-His');

        $state->fill([
            'mode'                => EnforcementState::ENFORCE,
            'cleared_report_id'   => $reportId,
            'cleared_by_user_id'  => $request->user()->id,
            'cleared_at'          => now(),
            'policy_version'      => (int) $state->policy_version + 1,
        ])->save();

        return response()->json(['ok' => true, 'data' => $state, 'report_id' => $reportId]);
    }

    /**
     * Turn enforcement ON directly — no learning period.
     *
     * 27-Aug-2026 (Ejaz): "ensure there is Only Enforcement ON or OFF and no learn option."
     * The learning period was there to discover which programs a client runs from outside
     * %WINDIR% / %PROGRAMFILES%. That work is done, the catalogue ships with the package, and
     * repeating it per site delays protection the client is paying for. His estate, his call.
     *
     * Distinct from promote(), which stays exactly as it was: promote() is the honest
     * "a clean report cleared this" path and still refuses without one. This is the operator
     * saying "arm it, I know this estate" — recorded as such, with the admin's own id, so the
     * console can always tell which of the two happened.
     *
     * ⚠ What this skips is real: the first AppLocker deny rule turns the collection
     * deny-by-default, so a program in %LOCALAPPDATA% that nobody allowed will not launch.
     * The kill switch below is the recovery and is never gated.
     */
    public function enable(Request $request): JsonResponse
    {
        $state = EnforcementState::forCompany((int) $request->user()->company_id);

        $state->fill([
            'mode'               => EnforcementState::ENFORCE,
            'cleared_report_id'  => 'ENABLED_DIRECTLY',
            'cleared_at'         => now(),
            'cleared_by_user_id' => $request->user()->id,
            // Endpoints re-fetch only when this moves. Without it the console would say
            // ENFORCE while every PC carried on with its previous policy.
            'policy_version'     => (int) $state->policy_version + 1,
            'disabled_at'        => null,
            'disabled_reason'    => null,
        ])->save();

        return response()->json(['ok' => true, 'data' => $state]);
    }

    /**
     * The kill switch. Always available, never gated, no confirmation ceremony.
     *
     * A machine that cannot be un-blocked is the failure we refuse to ship, so
     * this path has no preconditions at all. Endpoints pick it up on their next
     * heartbeat and remove their policy.
     */
    public function disable(Request $request): JsonResponse
    {

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $state = EnforcementState::forCompany((int) $request->user()->company_id);
        $state->fill([
            'mode'            => EnforcementState::OFF,
            'disabled_at'     => now(),
            'disabled_reason' => $data['reason'] ?? 'Disabled from the console',
            'policy_version'  => (int) $state->policy_version + 1,
        ])->save();

        return response()->json(['ok' => true, 'data' => $state]);
    }

    /**
     * Mark an unexpected target as handled — allowed, or judged irrelevant — so
     * it stops holding up promotion.
     *
     * Before 21-Sep-2026 this ONLY stamped resolved_at, whatever the console
     * button was labelled ("Allow / dismiss"). That satisfied the promotion
     * gate but changed nothing about what any endpoint actually blocks — the
     * program kept being closed by the agent or refused by AppLocker forever,
     * which reached Ejaz as "I already allowed this, why does it keep getting
     * blocked". ALLOW now does the real work: it creates/updates the ALLOWED
     * policy_rule AND mirrors it into the legacy allowed_apps/allowed_sites
     * list, so both a >=0.15 agent (which reads policy_rules status) and an
     * agent at or below 0.14 (which only ever reads the legacy list) stop
     * treating it as blocked. DISMISS keeps the old behaviour on purpose — for
     * "this is noise, not relevant here" rather than "this program is fine".
     */
    public function resolveAuditEvent(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['nullable', 'in:ALLOW,DISMISS'],
        ]);
        $decision = $data['decision'] ?? 'ALLOW';

        $companyId = (int) $request->user()->company_id;
        $row = EnforcementAuditEvent::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        if ($decision === 'ALLOW') {
            $this->allowTarget($companyId, (string) $row->target, (int) $request->user()->id);
        }

        $row->forceFill(['resolved_at' => now()])->save();

        return response()->json(['ok' => true, 'data' => $row]);
    }

    /**
     * Make one reported target actually stop being blocked.
     *
     * Writes BOTH representations an endpoint might read: the policy_rule row
     * (status=ALLOWED — what a >=0.15 agent and ComplianceEvaluator check) and
     * the legacy allowed_apps/blocked_apps (or …_sites) JSON list on the
     * resolved policy (what an agent at or below 0.14, and AppLocker's own
     * catalogue-driven deny rules via the enforcement service, ultimately
     * trace back to). Bumps the policy version either way, the same signal
     * every other rule edit uses to make endpoints re-sync within ~30s.
     */
    private function allowTarget(int $companyId, string $target, int $userId): void
    {
        $target = trim($target);
        if ($target === '') {
            return;
        }

        // AppLocker/process reports usually carry a full path; the Rules
        // screen only ever stores a bare name. A target with no path
        // separator and no .exe that already looks like a domain is treated
        // as a website — mirrors admin.blade.php's siteBlockable() heuristic.
        $base = basename(str_replace('\\', '/', $target));
        $isWebsite = $base === $target
            && ! str_contains(strtolower($target), '.exe')
            && (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/i', $target);

        $type = $isWebsite ? 'WEBSITE' : 'APPLICATION';
        $item = $isWebsite
            ? trim((string) preg_replace('#^https?://#', '', (string) preg_replace('#^www\.#', '', strtolower($target))), '/ ')
            : trim((string) preg_replace('/\.exe$/i', '', strtolower($base)));

        if ($item === '') {
            return;
        }

        $model = $type === 'APPLICATION' ? ApplicationPolicy::class : WebsitePolicy::class;
        $allowedKey = $type === 'APPLICATION' ? 'allowed_apps' : 'allowed_sites';
        $blockedKey = $type === 'APPLICATION' ? 'blocked_apps' : 'blocked_sites';

        // The one company-wide policy the Rules screen manages (created there
        // as "Company App/Website Rules" the first time it is saved). Reuse it
        // rather than creating a second, competing policy of the same type.
        $policy = $model::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->first();

        if (! $policy) {
            $policy = $model::create([
                'company_id' => $companyId,
                'name'       => $type === 'APPLICATION' ? 'Company App Rules' : 'Company Website Rules',
                'version'    => 1,
                $allowedKey  => [],
                $blockedKey  => [],
                'categories' => [],
            ]);
            PolicyAssignment::create([
                'company_id'          => $companyId,
                'policy_type'         => $type,
                'policy_id'           => $policy->id,
                'assignable_type'     => 'COMPANY',
                'assignable_id'       => $companyId,
                'assigned_by_user_id' => $userId,
            ]);
        }

        $allowed = (array) ($policy->{$allowedKey} ?? []);
        $blocked = (array) ($policy->{$blockedKey} ?? []);

        if (! in_array($item, array_map('strtolower', array_map('strval', $allowed)), true)) {
            $allowed[] = $item;
        }
        $blocked = array_values(array_filter($blocked, static fn ($b): bool => strtolower((string) $b) !== $item));

        $policy->{$allowedKey} = $allowed;
        $policy->{$blockedKey} = $blocked;
        $policy->version = (int) $policy->version + 1;
        $policy->save();

        $rule = PolicyRule::withoutGlobalScopes()->firstOrNew([
            'company_id'  => $companyId,
            'policy_type' => $type,
            'policy_id'   => $policy->id,
            'item'        => $item,
        ]);
        $rule->forceFill([
            'label'   => $rule->label ?? $item,
            'status'  => 'ALLOWED',
            // Never downgrade an existing rule's action; a freshly-created one
            // starts at WARN like every other new rule on the Rules screen.
            'action'  => $rule->exists ? $rule->action : 'WARN',
            'version' => (int) ($rule->version ?? 0) + 1,
        ]);
        $rule->save();
    }

    /**
     * The two learning-period endpoints, on an installation that has no learning period.
     *
     * 27-Aug-2026 (Ejaz): "there should be no learning mechanism in the client."
     *
     * Refused rather than quietly redirected to enable(). start-audit and promote are the two
     * routes that WRITE AUDIT, so leaving them live would let an old console build, a bookmarked
     * URL or a stale cached asset put a client site back into learning — which is precisely
     * what was removed. They are refused with the one path that does exist, named, so nobody is
     * left guessing which button to press instead.
     *
     * 422, not 404: the route exists and the request was understood. It is the installation
     * that has no such state.
     */
    private function refuseWhenLearningRemoved(): ?JsonResponse
    {
        if (EnforcementState::learningEnabled()) {
            return null;
        }

        return response()->json([
            'ok'      => false,
            'message' => 'There is no learning period on this installation. The application '
                . 'catalogue ships with SmartEPT, so there is nothing left for this site to '
                . 'discover. Enforcement has two states: turn it on, or leave it off.',
            'data'    => ['learning_enabled' => false, 'use_instead' => 'POST /api/enforcement/enable'],
        ], 422);
    }

    /**
     * Every condition that must hold before a tenant may enforce.
     *
     * @return array{allowed:bool, reason:string, unexpected:int, audit_days:float, reporting:bool}
     */
    private function promotionCheck(EnforcementState $state, int $unexpected, int $totalEvents): array
    {
        $minutes = $state->auditMinutes();

        $reason = '';
        $allowed = true;

        if ($state->mode !== EnforcementState::AUDIT) {
            $allowed = false;
            $reason = 'This tenant is not in audit mode. Start the learning period first.';
        } elseif ($totalEvents === 0) {
            // The single most dangerous false positive: an empty report reads as
            // "clean" and is usually "the service is not running".
            $allowed = false;
            $reason = 'No endpoint has reported anything yet. An empty report is not a clean report — '
                . 'it usually means the enforcement service is not running, or nobody has opened a blocked application.';
        } elseif ($minutes < EnforcementState::minAuditMinutes()) {
            $allowed = false;
            $reason = sprintf(
                'The learning period is not finished — %s to go. Employees have not yet used everything they normally use.',
                $state->auditRemainingLabel()
            );
        } elseif ($unexpected > 0) {
            $allowed = false;
            $reason = sprintf(
                '%d programs would be blocked that are not on your rules. Each one is something staff currently use. '
                . 'Allow them or dismiss them before enforcing.',
                $unexpected
            );
        }

        return [
            'allowed'       => $allowed,
            'reason'        => $reason,
            'unexpected'    => $unexpected,
            'audit_minutes' => $minutes,
            'audit_days'    => $state->auditDays(),
            'reporting'     => $totalEvents > 0,
        ];
    }

    /**
     * The identifiers this tenant deliberately blocks, lowercased.
     *
     * @return array<int,string>
     */
    private function expectedTargets(int $companyId): array
    {
        $out = [];

        $rules = PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->enforcing()
            ->get(['item', 'label', 'identifiers']);

        foreach ($rules as $rule) {
            foreach ([$rule->item, $rule->label] as $v) {
                if (filled($v)) {
                    $out[] = mb_strtolower((string) $v);
                }
            }
            foreach ((array) ($rule->identifiers ?? []) as $v) {
                if (is_string($v) && $v !== '') {
                    $out[] = mb_strtolower($v);
                }
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * @param array<int,string> $expected
     */
    private function isExpected(string $target, array $expected): bool
    {
        $t = mb_strtolower($target);
        foreach ($expected as $e) {
            if ($e !== '' && str_contains($t, $e)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Company-wide device control: USB / removable storage and the camera
     * device. Both are real Windows device-level blocks enforced by the service;
     * the admin flips a switch and every enrolled PC applies (or releases) it on
     * its next sync (~30s).
     */
    public function deviceControl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'block_removable_storage' => ['sometimes', 'boolean'],
            'block_camera_device'     => ['sometimes', 'boolean'],
            'block_browser_uploads'   => ['sometimes', 'boolean'],
            'block_media_streaming'   => ['sometimes', 'boolean'],
        ]);
        if ($data === []) {
            return response()->json(['error' => 'nothing to change'], 422);
        }
        $companyId = (int) $request->user()->company_id;

        \App\Models\Company::withoutGlobalScopes()->whereKey($companyId)->update($data);

        // Move the policy version so enrolled machines re-sync promptly.
        $state = EnforcementState::forCompany($companyId);
        $state->forceFill(['policy_version' => (int) ($state->policy_version ?? 0) + 1])->save();

        return response()->json(['ok' => true] + $data);
    }
}
