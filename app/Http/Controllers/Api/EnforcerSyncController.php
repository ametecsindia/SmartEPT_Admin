<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPolicy;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\EnforcementAuditEvent;
use App\Models\EnforcementMachine;
use App\Models\EnforcementState;
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
        $mode = $state->mode;
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
        if ($state->mode === EnforcementState::OFF) {
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

        if ($baseline !== [] || $sites !== []) {
            $specs[] = [
                'version'   => $version,
                'mode'      => $state->mode,
                'scope'     => 'MACHINE',
                'tenant_id' => (string) $companyId,
                'clearance' => $this->clearance($state),
                'rules'     => $baseline,
                'sites'     => $sites,
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

            if ($rules !== []) {
                $specs[] = [
                    'version'   => $version,
                    'mode'      => $state->mode,
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
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function primaryPolicy(string $model, int $companyId, string $label): ?object
    {
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

        return PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('policy_type', 'WEBSITE')
            ->where('policy_id', $policy->id)
            ->enforcing()
            ->pluck('item')
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
     */
    private function employeeHasAnythingToEnforce(Employee $employee): bool
    {
        $companyId = (int) $employee->company_id;

        return $this->rulesFor($companyId) !== [] || $this->sitesFor($companyId) !== [];
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
