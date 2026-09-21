<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPolicy;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\EnforcementAuditEvent;
use App\Models\EnforcementMachine;
use App\Models\EnforcementState;
use App\Models\PolicyAssignment;
use App\Models\PolicyRule;
use App\Models\WebsitePolicy;
use App\Services\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What the Windows enforcement service talks to.
 *
 * Separate from the agent endpoints on purpose. Those require `active-employee`
 * middleware and an employee token; this service authenticates as a MACHINE and
 * must work at boot with nobody signed in — which is the entire reason it has a
 * credential of its own (decision 9).
 *
 * Three endpoints:
 *
 *   POST heartbeat  report health, receive mode + version + kill switch
 *   GET  policy     WHAT to block — never AppLocker XML, see below
 *   POST audit      what the endpoint stopped, or would have
 *
 * WHY THIS DOES NOT SEND AppLocker XML
 * -----------------------------------
 * The invariants that stop a policy bricking a machine — the mandatory allow
 * set, Exe-requires-Appx, no path-based denies, never the Dll collection — live
 * in the Go generator compiled into the endpoint, with tests. Generating XML in
 * PHP would move them to the far side of a network call, into a language where
 * nothing tests them, maintained by whoever last touched the console.
 *
 * So the server says WHAT to block. The endpoint decides HOW, and refuses to
 * build a dangerous policy even if we ask it to.
 */
class EnforcerSyncController extends Controller
{
    /** POST /api/enforcer/heartbeat */
    public function heartbeat(Request $request): JsonResponse
    {
        $machine = $this->machine($request);

        $data = $request->validate([
            'device_uuid'            => ['nullable', 'string', 'max:64'],
            'enforcer_version'       => ['nullable', 'string', 'max:32'],
            'enforcement_level'      => ['nullable', 'in:' . implode(',', EnforcementMachine::LEVELS)],
            'enforcement_health'     => ['nullable', 'in:' . implode(',', EnforcementMachine::HEALTH)],
            'applied_policy_version' => ['nullable', 'integer', 'min:0'],
            'windows_sid'            => ['nullable', 'string', 'max:184'],
            // Who is signed in to the AGENT on this PC right now, or absent for
            // nobody. Blocking is a property of the person, not the machine.
            'employee_id'            => ['nullable', 'integer'],
        ]);

        $machine->forceFill([
            'enforcer_version'       => $data['enforcer_version'] ?? $machine->enforcer_version,
            'enforcement_level'      => $data['enforcement_level'] ?? $machine->enforcement_level,
            'enforcement_health'     => $data['enforcement_health'] ?? $machine->enforcement_health,
            'applied_policy_version' => $data['applied_policy_version'] ?? $machine->applied_policy_version,
            'windows_sid'            => $data['windows_sid'] ?? $machine->windows_sid,
            // Stored so the console can show WHOSE rules a PC is applying, and
            // so /policy answers for the same person the heartbeat asked about.
            // Preserved when absent, like every sibling field here. Without the
            // fallback an endpoint too old to report it nulled the column on
            // every heartbeat, so the console showed "nobody signed in" for a
            // PC somebody was demonstrably working at.
            'signed_in_employee_id'  => array_key_exists('employee_id', $data)
                ? ($data['employee_id'] ?: null)
                : $machine->signed_in_employee_id,
            'device_uuid'            => $data['device_uuid'] ?: $machine->device_uuid,
            'last_seen_at'           => now(),
        ])->save();

        // Mirror onto the agent's device row where one is linked, so the
        // console shows ONE endpoint rather than an agent and a service that
        // disagree about whether the machine is protected.
        if ($machine->device_uuid) {
            EmployeeDevice::where('device_uuid', $machine->device_uuid)
                ->update([
                    'enforcer_version'       => $machine->enforcer_version,
                    'enforcement_level'      => $machine->enforcement_level,
                    'enforcement_health'     => $machine->enforcement_health,
                    'applied_policy_version' => $machine->applied_policy_version,
                    'enforcement_reported_at' => now(),
                ]);
        }

        $state = EnforcementState::forCompany((int) $machine->company_id);
        $latest = app(PolicyResolver::class)->latestPolicyVersionFor((int) $machine->company_id);

        // Blocking follows the PERSON, not the machine.
        //
        //   nobody signed in            -> OFF. There is no employee to control.
        //   signed in, exempt           -> OFF.
        //   signed in, no rules of      -> OFF. "No rules" must mean nothing is
        //     their own                    blocked, not "fall back to whatever
        //                                  the last person had".
        //   signed in, has rules        -> the tenant's mode, AUDIT or ENFORCE.
        //
        // OFF is what the endpoint already treats as "remove everything", so a
        // sign-out, a rule-less employee and an exempt one all reach the same
        // well-tested path instead of three new ones.
        //
        // ABSENT is not ZERO.
        //
        // An endpoint that does not send employee_id at all is one that predates
        // this feature. It has no idea who is signed in, and reading its silence
        // as "nobody is here" switched enforcement OFF on every such machine —
        // which is every machine in the field the moment this shipped. Older
        // endpoints keep the behaviour they had; only an endpoint that
        // explicitly reports 0 is saying the desk is empty.
        // effectiveMode(), not ->mode. On an installation with no learning period a stored
        // AUDIT is answered OFF, so an endpoint never restarts the would-have-blocked
        // collection that was deliberately removed. Everywhere else the two are identical.
        $mode = $state->effectiveMode();
        $reportsSessions = array_key_exists('employee_id', $data);
        $signedIn = $reportsSessions
            ? $this->signedInEmployeeById($machine, $data['employee_id'])
            : null;

        if ($reportsSessions) {
            if (! $signedIn) {
                $mode = EnforcementState::OFF;
            } elseif (! $this->employeeIsEnforced($signedIn, $machine)) {
                $mode = EnforcementState::OFF;
            } elseif (! $this->employeeHasAnythingToEnforce($signedIn)) {
                $mode = EnforcementState::OFF;
            }
        }

        // A revoked machine is told to stop enforcing, but NOT via the kill
        // switch: revoking a credential must not be a quiet way to disarm a PC.
        // It simply stops receiving policy.
        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'enforcement' => [
                'mode'                  => $mode,
                'latest_policy_version' => $latest,
                'resync_required'       => (int) ($machine->applied_policy_version ?? 0) !== $latest,
                // The one flag that removes a policy. Nothing else does.
                'kill_switch'           => $mode === EnforcementState::OFF,
                // For the console, so "why is that PC not blocking" is a glance
                // rather than an investigation.
                'employee_id'           => $signedIn?->id,
            ],
        ]);
    }

    /**
     * GET /api/enforcer/policy
     *
     * Returns one spec per scope this endpoint should hold: the machine
     * baseline always, plus the signed-in employee's overlay when we know who
     * that is.
     */
    public function policy(Request $request): JsonResponse
    {
        $machine = $this->machine($request);
        $companyId = (int) $machine->company_id;

        $state = EnforcementState::forCompany($companyId);
        // effectiveMode() throughout this method: a stored AUDIT on an installation with no
        // learning period is OFF, and every spec below must carry the same mode the heartbeat
        // reported thirty seconds ago — an endpoint told OFF and then handed an AUDIT spec is
        // exactly the disagreement that leaves a PC applying a policy nobody thinks it has.
        $mode = $state->effectiveMode();
        if ($mode === EnforcementState::OFF) {
            // Not the same as "remove everything" — the heartbeat's kill switch
            // says that. This says there is nothing to apply.
            return response()->json(['ok' => true, 'data' => []]);
        }

        $version = app(PolicyResolver::class)->latestPolicyVersionFor($companyId);
        $specs = [];

        // 1. The machine baseline — company-level rules, applied from boot with
        //    nobody signed in.
        //
        //    Resolved from the COMPANY policy rather than the branch, because
        //    devices are bound to employees today and no device -> branch
        //    mapping exists yet. Company-wide is the correct conservative
        //    baseline until it does; branch refinement is additive.
        $baseline = $this->rulesFor($companyId);

        // Website rules ride on the MACHINE baseline and only there. Browser
        // policy lives in HKLM and the hosts file is machine-wide, so a site
        // block cannot be scoped to one signed-in employee — sending it on the
        // overlay would be a promise nothing on the endpoint can keep.
        $sites = $this->sitesFor($companyId);

        // Protections travel in their OWN list, never inside `rules`.
        //
        // Everything in `rules` becomes an AppLocker deny at the far end. A row
        // that says "WhatsApp is allowed, but not for sending files" put in that
        // list would stop WhatsApp opening at all — which is precisely the
        // mistake the requirement calls out: a protection must never be
        // implemented as killing the application. Separate list, separate
        // handling, and an endpoint that does not understand the key simply
        // enforces the full blocks as it always has.
        $protections    = $this->protectionsFor($companyId);
        // USB / removable-storage block: a company-level switch, machine-wide.
        // A real Windows driver block, so it rides the machine baseline like the
        // website rules do.
        $device = (array) (\App\Models\Company::withoutGlobalScopes()->whereKey($companyId)
            ->first(['block_removable_storage', 'block_camera_device', 'block_browser_uploads', 'block_media_streaming'])?->toArray() ?? []);
        $webProtections = $this->webProtectionsFor($companyId, (bool) ($device['block_browser_uploads'] ?? false));
        $blockRemovable = (bool) ($device['block_removable_storage'] ?? false);
        // Camera device block: same shape, disables the camera hardware itself.
        $blockCamera = (bool) ($device['block_camera_device'] ?? false);
        // Media Control: the browser-extension video block. Same company-wide
        // switch shape as the others; see mediaControlFor() for what it sends.
        $mediaControl = $this->mediaControlFor($companyId, (bool) ($device['block_media_streaming'] ?? false));

        if ($baseline !== [] || $sites !== [] || $protections !== [] || $webProtections !== null || $blockRemovable || $blockCamera || $mediaControl !== null) {
            $specs[] = [
                'version'                 => $version,
                'mode'                    => $mode,
                'scope'                   => 'MACHINE',
                'tenant_id'               => (string) $companyId,
                'clearance'               => $this->clearance($state),
                'rules'                   => $baseline,
                'sites'                   => $sites,
                'protections'             => $protections,
                'web_protections'         => $webProtections,
                'block_removable_storage' => $blockRemovable,
                'block_camera_device'     => $blockCamera,
                'media_control'           => $mediaControl,
            ];
        }

        // 2. The signed-in employee's overlay, if we know who they are AND we
        //    have their Windows SID. AppLocker rules are written against a SID,
        //    so without one there is no overlay to build — the machine simply
        //    stays on its baseline.
        // The SAME person the heartbeat asked about.
        //
        // This used to resolve from device OWNERSHIP while the heartbeat
        // resolved from what the endpoint reported. On a three-shift shared desk
        // the heartbeat answered for whoever was actually at the keyboard and
        // this built the overlay for whoever the PC was registered to.
        $employee = $this->signedInEmployeeFor($machine);
        if ($employee && $machine->windows_sid) {
            $bundle = app(PolicyResolver::class)->bundleForEmployee($employee, $employee->currentDevice ?? null);
            $rules = $this->rulesFromBundle($bundle);

            // The employee's own media-control override, if they have one.
            // null means "inherit the company switch", already covered by the
            // MACHINE-scope spec above — nothing is sent for it in that case,
            // and unlike $rules it must not be what gates whether this spec is
            // built: an employee with a media override but no application
            // rules of their own still needs an EMPLOYEE spec to carry it.
            $employeeMedia = $this->mediaControlForEmployee($employee);

            if ($rules !== [] || $employeeMedia !== null) {
                $spec = [
                    'version'   => $version,
                    'mode'      => $mode,
                    'scope'     => 'EMPLOYEE',
                    'tenant_id' => (string) $companyId,
                    'clearance' => $this->clearance($state),
                    'employee'  => [
                        'employee_id'   => $employee->id,
                        'employee_code' => $employee->employee_code,
                        'name'          => trim((string) $employee->first_name . ' ' . (string) $employee->last_name),
                        'login'         => null, // the endpoint knows its own account name
                        'windows_sid'   => $machine->windows_sid,
                        'device_uuid'   => $machine->device_uuid,
                    ],
                    'rules'     => $rules,
                ];
                if ($employeeMedia !== null) {
                    $spec['media_control'] = $employeeMedia;
                }
                $specs[] = $spec;
            }
        }

        return response()->json(['ok' => true, 'data' => $specs]);
    }

    /**
     * POST /api/enforcer/audit
     *
     * NOT named audit(): the base Controller already has an audit() helper for
     * writing the audit log, with a different signature. Declaring an endpoint
     * of the same name is a PHP fatal at class load, which took out every route
     * on this controller — heartbeat, policy and this one — with a 500 that
     * named a method nobody was calling.
     */
    public function storeAudit(Request $request): JsonResponse
    {
        $machine = $this->machine($request);

        $data = $request->validate([
            'device_uuid'          => ['nullable', 'string', 'max:64'],
            'events'               => ['required', 'array', 'min:1', 'max:500'],
            'events.*.target'      => ['required', 'string', 'max:512'],
            'events.*.outcome'     => ['nullable', 'in:WOULD_BLOCK,BLOCKED,ALLOWED_BY_RULE'],
            'events.*.source'      => ['nullable', 'in:APPLOCKER,FIREWALL,PROCESS'],
            'events.*.rule_name'   => ['nullable', 'string', 'max:191'],
            'events.*.occurrences' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ]);

        $expected = $this->expectedTargets((int) $machine->company_id);
        $deviceUuid = $machine->device_uuid ?: ('machine-' . $machine->id);
        $stored = 0;

        foreach ($data['events'] as $e) {
            $target = trim((string) $e['target']);
            if ($target === '') {
                continue;
            }
            $hash = hash('sha256', mb_strtolower($target));
            $seenAt = isset($e['occurred_at']) ? \Illuminate\Support\Carbon::parse($e['occurred_at']) : now();

            $row = EnforcementAuditEvent::withoutGlobalScopes()->firstOrNew([
                'company_id'  => $machine->company_id,
                'device_uuid' => $deviceUuid,
                'target_hash' => $hash,
            ]);

            $row->target        = $target;
            $row->target_hash   = $hash;
            $row->source        = $e['source'] ?? 'APPLOCKER';
            $row->outcome       = $e['outcome'] ?? 'WOULD_BLOCK';
            $row->rule_name     = $e['rule_name'] ?? $row->rule_name;
            $row->expected      = $this->isExpected($target, $expected);
            // The endpoint has already collapsed repeats, so this is a count,
            // not an increment — re-reporting the same window must not inflate it.
            $row->occurrences   = max((int) ($row->occurrences ?? 0), (int) ($e['occurrences'] ?? 1));
            $row->first_seen_at = $row->first_seen_at ?: $seenAt;
            $row->last_seen_at  = $seenAt;
            $row->save();

            $stored++;
        }

        $machine->forceFill(['enforcement_reported_at' => now(), 'last_seen_at' => now()])->save();

        return response()->json(['ok' => true, 'stored' => $stored], 201);
    }

    // ---- helpers ----------------------------------------------------------

    /** The authenticated machine, or 403. */
    private function machine(Request $request): EnforcementMachine
    {
        $machine = $request->user();

        abort_unless($machine instanceof EnforcementMachine, 403, 'Enforcer credential required.');
        abort_unless($request->user()->tokenCan('enforcer'), 403, 'Enforcer credential required.');
        abort_unless($machine->isActive(), 403, 'This endpoint has been revoked.');

        return $machine;
    }

    /**
     * The clearance the generator needs before it will build an enforcing
     * policy. Null in audit mode, where none is required.
     */
    private function clearance(EnforcementState $state): ?array
    {
        if ($state->mode !== EnforcementState::ENFORCE) {
            return null;
        }

        return [
            'report_id'         => $state->cleared_report_id ?: 'unrecorded',
            'cleared_by'        => (string) ($state->cleared_by_user_id ?: 'console'),
            // Zero by definition: promotion is refused while any remain, so a
            // tenant in ENFORCE has none outstanding.
            'unexpected_blocks' => 0,
        ];
    }


    /**
     * The one policy of a type that a company's machine baseline is built from.
     *
     * This used to be a bare ->first() with no ordering. With more than one
     * policy of the same type for a company, which one MySQL returned was
     * undefined — so the other policy's rules simply did not reach any PC, and
     * nobody was told. An admin sets a rule, sees it saved in the console, and
     * it blocks nothing. That is the exact class of silent failure enforcement
     * exists to remove, so it cannot be tolerated in the code that builds it.
     *
     * Ordering by id makes it deterministic: the oldest policy is the company's
     * primary. A second one is NOT merged in — a union would change what a
     * client blocks, which is their decision and not ours — but it is written to
     * the log every time a spec is built, so it is impossible to sit unnoticed.
     *
     * 21-Sep-2026: that oldest-wins fallback is now second choice. PolicyResolver
     * (the per-employee agent bundle) picks the company's policy by walking
     * PolicyAssignment, not by age — so a company with two WEBSITE policies
     * (say, an old one from the generic Policies tab, still carrying
     * "youtube.com" in blocked_sites, plus the current one the Rules screen
     * shows and edits) had the machine baseline enforcing the OLD one while the
     * agent and the admin console both agreed on the new one: the exact "the
     * console shows it's not blocked, but it's blocked anyway" report this
     * fixes. Resolving through the same PolicyAssignment table both endpoints
     * agree, and the oldest-row heuristic below only fires for a tenant that
     * predates PolicyAssignment ever being written for it.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function primaryPolicy(string $model, int $companyId, string $label): ?object
    {
        $assignedId = PolicyAssignment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('policy_type', $label)
            ->where('assignable_type', 'COMPANY')
            ->where('assignable_id', $companyId)
            ->orderByDesc('id') // most recently assigned wins — matches assignmentsFor()'s tie-break
            ->value('policy_id');

        if ($assignedId) {
            $assigned = $model::withoutGlobalScopes()->find($assignedId);
            if ($assigned) {
                return $assigned;
            }
        }

        $policies = $model::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        if ($policies->isEmpty()) {
            return null;
        }

        if ($policies->count() > 1) {
            Log::warning('enforcement: company has multiple policies of one type; only the oldest is enforced', [
                'company_id' => $companyId,
                'policy_type' => $label,
                'using_policy_id' => $policies->first()->id,
                'ignored_policy_ids' => $policies->slice(1)->pluck('id')->all(),
            ]);
        }

        return $policies->first();
    }

    /**
     * The company's enforcing application rules, as targets the generator can use.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rulesFor(int $companyId): array
    {
        $policy = $this->primaryPolicy(ApplicationPolicy::class, $companyId, 'APPLICATION');
        if (! $policy) {
            return [];
        }

        $rules = PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('policy_type', 'APPLICATION')
            ->where('policy_id', $policy->id)
            ->enforcing()
            ->get();

        return $this->toSpecRules($rules);
    }

    /**
     * Application rules that restrict an ACTIVITY rather than the application.
     *
     * Deliberately not filtered by status or action. An ALLOWED row carrying
     * File Sharing Block is the central case of this feature, and a row already
     * set to Full Block & Close is skipped instead — there is nothing to
     * protect inside a program that never starts, and sending both would have
     * the endpoint doing redundant work on every sync for ever.
     *
     * @return array<int,array<string,mixed>>
     */
    private function protectionsFor(int $companyId): array
    {
        $policy = $this->primaryPolicy(ApplicationPolicy::class, $companyId, 'APPLICATION');
        if (! $policy) {
            return [];
        }

        $rules = PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('policy_type', 'APPLICATION')
            ->where('policy_id', $policy->id)
            ->withProtections()
            ->get();

        $out = [];
        foreach ($rules as $rule) {
            $list = $rule->protectionList();
            if ($list === [] || $rule->isEnforcing()) {
                continue;
            }

            $spec = $this->specRule(
                (string) $rule->item,
                (string) ($rule->label ?: $rule->item),
                (array) ($rule->identifiers ?? []),
                $rule->confirmed_at !== null,
                $rule->catalog_app_id,
            );
            if (! $spec) {
                continue;
            }

            // The action is what the endpoint must NOT do here. Overwriting the
            // BLOCK that specRule() stamps on every rule is not cosmetic: an
            // endpoint that read this list and saw BLOCK would deny the
            // application, and the whole point is that it keeps running.
            $spec['action']      = 'PROTECT';
            $spec['protections'] = $list;

            $out[] = $spec;
        }

        return $out;
    }

    /**
     * Website protections, collapsed to the levers a browser actually has.
     *
     * Browsers expose no per-site upload control and no per-site camera block —
     * only a browser-level switch and, for the camera, an allow list. So the
     * server sends the DECISION (uploads off; camera off except these sites)
     * rather than a per-site list the endpoint could not honour. Sending the
     * per-site list would be a promise nothing on the PC can keep, which is the
     * same failure as a rule with no identifiers.
     *
     * @return array<string,mixed>|null
     */
    private function webProtectionsFor(int $companyId, bool $blockUploadsCompanyWide = false): ?array
    {
        $policy = $this->primaryPolicy(WebsitePolicy::class, $companyId, 'WEBSITE');
        if (! $policy) {
            // The company-wide switch needs no website rule to exist.
            return $blockUploadsCompanyWide
                ? ['block_uploads' => true, 'block_camera' => false, 'camera_allowed_urls' => [], 'requested_by' => []]
                : null;
        }

        $rules = PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('policy_type', 'WEBSITE')
            ->where('policy_id', $policy->id)
            ->get();

        // Device control "Block browser uploads": the same browser-wide lever,
        // switched on for the company rather than asked for by a site rule.
        $uploads = $blockUploadsCompanyWide;
        $camera  = false;
        $items   = [];

        foreach ($rules as $rule) {
            if ($rule->isEnforcing()) {
                continue; // already a full site block; nothing to protect inside it
            }
            $list = $rule->protectionList();
            if ($list === []) {
                continue;
            }
            // A browser cannot tell an image upload from any other upload, so
            // File and Image Sharing Block collapse to the same switch here.
            // The console says so before the box is ticked.
            if (in_array('file', $list, true) || in_array('image', $list, true)) {
                $uploads = true;
            }
            if (in_array('camera', $list, true)) {
                $camera = true;
            }
            $items[] = ['item' => (string) $rule->item, 'protections' => $list];
        }

        if (! $uploads && ! $camera) {
            return null;
        }

        return [
            'block_uploads'       => $uploads,
            'block_camera'        => $camera,
            // Sites the admin explicitly marked Allowed. Without this,
            // switching the camera off for one site would take the company's
            // own video-meeting tool down with it. The same list also
            // exempts sites from Media Control, below — one "Allowed" list,
            // reused, rather than a second admin control for the same idea.
            'camera_allowed_urls' => $this->allowedWebsiteItems($companyId, $policy->id),
            // Kept so the endpoint's audit report can name the rules that asked
            // for this, rather than reporting an unexplained browser-wide change.
            'requested_by'        => $items,
        ];
    }

    /**
     * Media Control: whether the SmartEPT Media Control browser extension
     * should block video, company-wide, and which sites are exempted.
     *
     * Same shape and same reasoning as webProtectionsFor(): a browser
     * exposes only a global switch plus exceptions, never a per-site block,
     * so that is the DECISION this sends — never a per-site list.
     *
     * The exemption list reuses the WEBSITE policy's "Allowed" rows — the
     * same list webProtectionsFor() already builds for the camera — so
     * marking a site Allowed exempts it from both at once. A dedicated
     * exemption list can be split out later if a client ever needs the two
     * to differ; nobody has asked for that yet.
     *
     * @return array<string,mixed>|null
     */
    private function mediaControlFor(int $companyId, bool $enabledCompanyWide): ?array
    {
        if (! $enabledCompanyWide) {
            return null;
        }

        $policy = $this->primaryPolicy(WebsitePolicy::class, $companyId, 'WEBSITE');
        $allowed = $policy ? $this->allowedWebsiteItems($companyId, $policy->id) : [];

        return [
            'enabled'      => true,
            'allowed_urls' => $allowed,
        ];
    }

    /**
     * One employee's own media-control override, or null to inherit the
     * company-wide switch (already sent in the MACHINE-scope spec).
     *
     * media_block_mode is BLOCKED, ALLOWED or null — the same tri-state shape
     * as enforcement_mode/tracking_mode elsewhere on Employee. Unlike
     * mediaControlFor(), this MUST distinguish "off" from "no opinion": an
     * admin who set ALLOWED for one employee needs that to override an
     * enabled company switch, not just fail to add to it, so this returns an
     * explicit enabled=false rather than null when the override is ALLOWED.
     * See smartept-enforcer's store.MediaControl.Enabled doc comment for how
     * the two scopes layer on the endpoint.
     *
     * @return array<string,mixed>|null
     */
    private function mediaControlForEmployee(Employee $employee): ?array
    {
        $mode = strtoupper((string) ($employee->media_block_mode ?? ''));
        if (! in_array($mode, ['BLOCKED', 'ALLOWED'], true)) {
            return null;
        }

        $companyId = (int) $employee->company_id;
        $policy = $this->primaryPolicy(WebsitePolicy::class, $companyId, 'WEBSITE');
        $allowed = $policy ? $this->allowedWebsiteItems($companyId, $policy->id) : [];

        return [
            'enabled'      => $mode === 'BLOCKED',
            'allowed_urls' => $allowed,
        ];
    }

    /**
     * Sites the admin explicitly marked Allowed on the WEBSITE policy.
     * Shared by webProtectionsFor() (camera exemptions) and mediaControlFor()
     * (video exemptions) — one query, one source of truth for "sites this
     * company has vouched for", rather than two copies that could drift.
     *
     * @return array<int,string>
     */
    private function allowedWebsiteItems(int $companyId, int $policyId): array
    {
        return PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('policy_type', 'WEBSITE')
            ->where('policy_id', $policyId)
            ->where('status', 'ALLOWED')
            ->pluck('item')
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The company's enforcing website rules, as the admin typed them.
     *
     * Sent raw, not pre-resolved into domains: only the endpoint knows which
     * shapes its build can actually block, and it reports by name the ones it
     * cannot rather than dropping them silently. A domain list invented here
     * would go stale the first time the endpoint is upgraded.
     *
     * @return array<int,string>
     */
    private function sitesFor(int $companyId): array
    {
        $policy = $this->primaryPolicy(WebsitePolicy::class, $companyId, 'WEBSITE');
        if (! $policy) {
            return [];
        }

        $blocked = PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('policy_type', 'WEBSITE')
            ->where('policy_id', $policy->id)
            ->enforcing()
            ->pluck('item')
            ->map(fn ($v) => trim((string) $v))
            ->filter();

        return $blocked->merge($this->videoCdnSitesFor($companyId, $policy->id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The video-CDN domains a `video` protection actually resolves to, for
     * sites where that mechanism has a real domain to block (see
     * config('protections.video_cdn_domains')). Added to the SAME site
     * blocklist as a full block — a CDN-only domain refused there is
     * indistinguishable, to the endpoint, from any other blocked host — while
     * the site's own domain is deliberately never added on its behalf, which
     * is what keeps the site itself reachable.
     *
     * Only ever reads non-enforcing rows: a row already BLOCKED/VIOLATION
     * clears its protections client-side (admin.blade.php) before it ever
     * reaches here, so an item cannot be both "site blocked" and "video
     * protected" at once — nothing here needs to guard against that.
     *
     * @return array<int,string>
     */
    private function videoCdnSitesFor(int $companyId, int $policyId): array
    {
        $domains = (array) config('protections.video_cdn_domains', []);
        if ($domains === []) {
            return [];
        }

        $rules = PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('policy_type', 'WEBSITE')
            ->where('policy_id', $policyId)
            ->withProtections()
            ->get(['item', 'protections']);

        $out = [];
        foreach ($rules as $rule) {
            $item = strtolower(trim((string) $rule->item));
            if (! $rule->hasProtection('video') || ! isset($domains[$item])) {
                continue;
            }
            foreach ((array) $domains[$item] as $cdn) {
                $out[] = (string) $cdn;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function rulesFromBundle(array $bundle): array
    {
        $out = [];
        foreach ((array) ($bundle['policies']['application']['rules'] ?? []) as $r) {
            if (empty($r['enforced'])) {
                continue;
            }
            $out[] = $this->specRule(
                (string) ($r['item'] ?? ''),
                (string) ($r['label'] ?? $r['item'] ?? ''),
                (array) ($r['identifiers'] ?? []),
                (bool) ($r['confirmed'] ?? false),
            );
        }

        return array_values(array_filter($out));
    }

    /** @return array<int,array<string,mixed>> */
    private function toSpecRules($rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $spec = $this->specRule(
                (string) $rule->item,
                (string) ($rule->label ?: $rule->item),
                (array) ($rule->identifiers ?? []),
                $rule->confirmed_at !== null,
                $rule->catalog_app_id,
            );
            if ($spec) {
                $out[] = $spec;
            }
        }

        return $out;
    }

    /**
     * One rule, as targets.
     *
     * A rule with no identifiers is skipped rather than guessed at. A rule
     * built from a typed-in name would produce an AppLocker rule that looks
     * correct in the console and blocks nothing — which is the exact failure
     * this whole project exists to remove. The catalogue supplies identifiers;
     * a free-text rule stays with the agent, which can still close it by name.
     *
     * @return array<string,mixed>|null
     */
    private function specRule(string $item, string $label, array $identifiers, bool $confirmed, ?string $catalogId = null): ?array
    {
        $targets = [];

        if (! empty($identifiers['package_name']) || ! empty($identifiers['package_family_name'])) {
            $targets[] = [
                'kind'                => 'Appx',
                'package_name'        => (string) ($identifiers['package_name'] ?? ''),
                'package_family_name' => (string) ($identifiers['package_family_name'] ?? ''),
                'publisher'           => (string) ($identifiers['package_publisher'] ?? ''),
            ];
        }
        if (! empty($identifiers['executable']) || ! empty($identifiers['publisher'])) {
            $targets[] = [
                'kind'       => 'Exe',
                'executable' => (string) ($identifiers['executable'] ?? ''),
                'publisher'  => (string) ($identifiers['publisher'] ?? ''),
            ];
        }

        // A rule with no identifiers used to be DROPPED here, which is why
        // nothing was ever blocked: the Rules screen sends the item an admin
        // typed and nothing else, so every hand-made rule arrived empty and
        // silently disappeared. The machine then received a policy with zero
        // rules and reported itself healthy.
        //
        // The endpoint is the right place to fill this in — it embeds the
        // application catalogue, so it resolves "anydesk" to every variant
        // AnyDesk takes on a real PC, including the Store package a hand-typed
        // rule always misses. Send the item and let it do that.
        return [
            'rule_id'        => 'rule-' . substr(hash('sha256', $item), 0, 16),
            'item'           => $item,
            'label'          => $label !== '' ? $label : $item,
            'action'         => 'BLOCK',
            'confirmed'      => $confirmed,
            'catalog_app_id' => $catalogId,
            'targets'        => $targets,
        ];
    }

    /** The employee currently signed in on this machine, if we can tell. */
    /**
     * The employee the ENDPOINT says is signed in, not the one who owns the PC.
     *
     * The agent reports this on every heartbeat. It is the only trustworthy
     * answer on a shared machine: device ownership says whose desk it is, which
     * on a three-shift floor is nobody's useful information.
     *
     * Falls back to the device owner only when the endpoint reported nothing at
     * all — an older agent that does not send it yet.
     */
    private function signedInEmployeeById(EnforcementMachine $machine, $employeeId)
    {
        if ($employeeId) {
            return Employee::withoutGlobalScopes()
                ->where('company_id', $machine->company_id)
                ->whereNull('deleted_at')
                ->find((int) $employeeId);
        }

        return null;
    }

    /**
     * Is this person inside enforcement at all? Resolved through the six levels.
     *
     * The DEVICE is passed, like every other caller of this resolver. Omitting
     * it skipped the most specific level in the hierarchy on the one call that
     * decides the kill switch — so an admin who set ENFORCED on a device to
     * override an employee-level EXEMPT saw the employee screen agree with them
     * (it resolves WITH the device) while the heartbeat answered OFF and the
     * endpoint removed the policy within thirty seconds.
     */
    private function employeeIsEnforced(Employee $employee, ?EnforcementMachine $machine = null): bool
    {
        $device = $machine && $machine->device_uuid
            ? EmployeeDevice::where('device_uuid', $machine->device_uuid)->first()
            : null;

        return app(PolicyResolver::class)->effectiveEnforcementMode($employee, $device)
            === \App\Support\EnforcementMode::ENFORCED;
    }

    /**
     * The rules that apply to THIS employee.
     *
     * Today that is the company's enforcing rules, because rules are held at
     * company level. The point of routing it through here is that when rules
     * become assignable per employee, ONE method changes and both the heartbeat
     * and the policy endpoint follow.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rulesForEmployee(Employee $employee): array
    {
        return $this->rulesFor((int) $employee->company_id);
    }

    /**
     * Does this employee have ANYTHING to enforce — applications OR websites?
     *
     * This decides whether the endpoint is told OFF, so getting it wrong takes a
     * whole tenant offline. It did: it asked rulesForEmployee(), which returns
     * only APPLICATION rules, so a client whose rules were all websites — or
     * whose application rules were not armed — was answered OFF, and then
     * NOTHING was blocked, websites included.
     *
     * "No rules" has to mean no rules of either kind.
     *
     * 16-Sep-2026: extended for the same reason the websites gap above was a
     * bug. An employee whose ONLY policy is their own media-block override
     * (media_block_mode, no application or website rules of their own) has
     * something real to enforce — omitting it here would report OFF, the
     * endpoint would disarm on this employee's next heartbeat, and the
     * override the admin set would never actually reach the machine.
     */
    private function employeeHasAnythingToEnforce(Employee $employee): bool
    {
        $companyId = (int) $employee->company_id;

        return $this->rulesFor($companyId) !== []
            || $this->sitesFor($companyId) !== []
            || $this->mediaControlForEmployee($employee) !== null;
    }

    /**
     * Who this machine reported, falling back to the device owner.
     *
     * One source of truth for both endpoints. The endpoint's own report wins
     * because it is the only thing that knows who is signed in RIGHT NOW;
     * ownership is a reasonable guess for an endpoint too old to report it.
     */
    private function signedInEmployeeFor(EnforcementMachine $machine)
    {
        if ($machine->signed_in_employee_id) {
            $e = Employee::withoutGlobalScopes()->find($machine->signed_in_employee_id);
            if ($e) {
                return $e;
            }
        }

        return $this->signedInEmployee($machine);
    }

    private function signedInEmployee(EnforcementMachine $machine)
    {
        if (! $machine->device_uuid) {
            return null;
        }

        $device = EmployeeDevice::where('device_uuid', $machine->device_uuid)->first();

        return $device?->employee;
    }

    /** @return array<int,string> */
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

    /** @param array<int,string> $expected */
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
}
