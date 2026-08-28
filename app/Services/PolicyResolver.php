<?php

namespace App\Services;

use App\Models\ApplicationPolicy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Team;
use App\Models\AttendancePolicy;
use App\Models\BreakPolicy;
use App\Models\CompliancePolicy;
use App\Models\DevicePolicy;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\EnforcementState;
use App\Models\MonitoringPolicy;
use App\Models\NetworkPolicy;
use App\Models\PolicyAssignment;
use App\Models\PolicyRule;
use App\Models\ScreenshotPolicy;
use App\Models\Shift;
use App\Models\UsbPolicy;
use App\Models\VpnProxyPolicy;
use App\Models\WebcamPolicy;
use App\Models\WebsitePolicy;
use App\Support\EnforcementMode;
use Illuminate\Support\Facades\DB;

/**
 * The heart of the SmartEPT Policy Engine.
 *
 * Resolves the effective policy of each type for an employee/device by walking the
 * assignment precedence DEVICE > EMPLOYEE > TEAM > DEPARTMENT > BRANCH > COMPANY
 * (most specific wins), then composes them into the single bundle the agent obeys.
 *
 * A device with no applicable assignment gets an empty bundle (tracking disabled) —
 * safe by default: nothing is captured that a policy has not enabled.
 */
class PolicyResolver
{
    /** policy_type => Eloquent model class */
    private const MODELS = [
        'MONITORING'  => MonitoringPolicy::class,
        'SCREENSHOT'  => ScreenshotPolicy::class,
        'WEBCAM'      => WebcamPolicy::class,
        'APPLICATION' => ApplicationPolicy::class,
        'WEBSITE'     => WebsitePolicy::class,
        'NETWORK'     => NetworkPolicy::class,
        'DEVICE'      => DevicePolicy::class,
        'USB'         => UsbPolicy::class,
        'VPN_PROXY'   => VpnProxyPolicy::class,
        'BREAK'       => BreakPolicy::class,
        'ATTENDANCE'  => AttendancePolicy::class,
        'COMPLIANCE'  => CompliancePolicy::class,
    ];

    /**
     * Compose the full agent policy bundle for one employee (optionally on a device).
     */
    public function bundleForEmployee(Employee $employee, ?EmployeeDevice $device = null): array
    {
        $chain = $this->assignableChain($employee, $device);
        $assignments = $this->assignmentsFor($employee->company_id, $chain);

        $policies = [];
        foreach (array_keys(self::MODELS) as $type) {
            $policies[strtolower($type)] = $this->resolveType($type, $chain, $assignments, $employee);
        }

        $monitoring = $policies['monitoring'] ?? null;
        $company = Company::find($employee->company_id);

        // Tracking mode override (Ejaz 19-Jul): most specific level in the chain wins.
        // Reflected into the policy flags too, so any server-side consumer that reads
        // the flags stops capturing even if it ignores tracking_mode directly.
        $trackingMode = $this->effectiveTrackingMode($employee, $device, $company);
        $this->applyTrackingMode($policies, $trackingMode);

        // Whether this PERSON is inside enforcement. Separate from the tenant
        // switch below: the tenant decides whether anything blocks at all, this
        // decides whether it blocks for them.
        $enforcementMode = $this->effectiveEnforcementMode($employee, $device, $company);

        // QA Phase 5 (B11/D5): surface the RESOLVED screenshot cadence explicitly so the
        // agent schedules on the effective value (not a stale/default) and stamps every
        // shot with the policy id + version it obeyed. This is where the "every minute
        // instead of every N" bug is diagnosable — effective_interval_seconds is authoritative.
        if (isset($policies['screenshot']) && is_array($policies['screenshot'])) {
            $s = $policies['screenshot'];
            $policies['screenshot']['policy_id'] = $s['id'] ?? null;
            $policies['screenshot']['policy_version'] = (int) ($s['version'] ?? 1);
            $policies['screenshot']['effective_interval_seconds'] = (int) ($s['interval_seconds'] ?? 600);
        }

        // Per-rule actions (21-Aug-2026). Each blocked item carries its own action
        // instead of sharing one policy-level action_on_blocked. The old arrays and
        // the policy-level action are left exactly as they were, so an agent at or
        // below 0.14 reads the bundle it has always read and behaves identically.
        $this->attachRules($policies, $employee->company_id);

        // Whether this tenant's enforcement is off, learning, or actually blocking.
        // No row means OFF: running an upgrade must never start blocking for anybody.
        $enforcement = EnforcementState::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->first();

        return [
            'employee_id'     => $employee->id,
            // Who this bundle was resolved FOR. The enforcement service stores the
            // policy against this reference locally, so an applied policy can always
            // be traced back to an employee — and reverted when they sign out and
            // the machine falls back to its baseline.
            'employee_ref'    => [
                'employee_id'  => $employee->id,
                'employee_code' => $employee->employee_code ?? null,
                'name'         => trim((string) ($employee->first_name ?? '') . ' ' . (string) ($employee->last_name ?? '')) ?: null,
                'user_id'      => $employee->user_id ?? null,
                'device_uuid'  => $device?->device_uuid,
                // The Windows account SID the agent last reported for this device.
                // AppLocker rules are written against a SID, so an employee overlay
                // cannot be generated without one.
                'windows_sid'  => $device?->windows_sid,
            ],
            'device_uuid'     => $device?->device_uuid,
            'company_id'      => $employee->company_id,
            'enforcement'     => [
                // effectiveMode(): on an installation with no learning period a stored AUDIT
                // is OFF, so the agent is never told "learning" about a state it cannot leave.
                'mode'            => $enforcement?->effectiveMode() ?? EnforcementState::OFF,
                'policy_version'  => (int) ($enforcement->policy_version ?? 1),
                'audit_started_at' => $enforcement?->audit_started_at?->toIso8601String(),
                // Per-employee. ENFORCED or EXEMPT, already resolved through the
                // six levels, so neither the agent nor the endpoint has to know
                // the hierarchy exists.
                'employee_mode'   => $enforcementMode,
                // The single boolean both clients act on: does THIS sign-in
                // block anything? Sent as well as the mode so a future third
                // value cannot silently change what an old agent does.
                'employee_enforced' => $enforcementMode === EnforcementMode::ENFORCED,
            ],
            // Bumped whenever any rule or the enforcement mode changes. The agent
            // heartbeat returns this value; an endpoint whose stored version differs
            // re-syncs. That is the whole push mechanism — there is no WebSocket.
            'latest_policy_version' => $this->latestPolicyVersionFor($employee->company_id),
            'consent_required' => (bool) ($monitoring['consent_required'] ?? true),
            'policy_version'  => (int) ($monitoring['version'] ?? 1),
            'tracking_mode'    => $trackingMode,
            // Raw-IP / local-IP browsing: keep the time as "Unknown source", store no content.
            'exclude_ip_sites' => (bool) ($company->exclude_ip_sites ?? true),
            'generated_at'    => now()->toIso8601String(),
            'agent'           => [
                'exit_lock_enabled'    => (bool) ($company->agent_exit_lock_enabled ?? false),
                'exit_password_sha256' => ($company && $company->agent_exit_lock_enabled && filled($company->agent_exit_password))
                    ? hash('sha256', $company->agent_exit_password) : null,
            ],
            'policies'        => $policies,
        ];
    }

    /**
     * Hang each policy's per-rule list off the resolved policy.
     *
     * The rules live in policy_rules, keyed by (policy_type, policy_id), so only
     * the rules belonging to the policy this employee actually resolved to are
     * shipped — not the whole tenant's.
     *
     * @param array<string,mixed> $policies
     */
    private function attachRules(array &$policies, int $companyId): void
    {
        $map = ['application' => 'APPLICATION', 'website' => 'WEBSITE'];

        foreach ($map as $key => $type) {
            if (! isset($policies[$key]) || ! is_array($policies[$key])) {
                continue;
            }
            $policyId = $policies[$key]['id'] ?? null;
            if (! $policyId) {
                $policies[$key]['rules'] = [];
                continue;
            }

            $policies[$key]['rules'] = PolicyRule::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('policy_type', $type)
                ->where('policy_id', $policyId)
                ->orderBy('item')
                ->get([
                    'item', 'label', 'status', 'action', 'suggested_action',
                    'catalog_app_id', 'identifiers', 'confirmed_at',
                ])
                ->map(static function (PolicyRule $r): array {
                    return [
                        'item'             => $r->item,
                        'label'            => $r->label,
                        'status'           => $r->status,
                        'action'           => $r->action,
                        'suggested_action' => $r->suggested_action,
                        'catalog_app_id'   => $r->catalog_app_id,
                        'identifiers'      => $r->identifiers,
                        // True only for the actions that actually prevent something.
                        // The agent still warns on the rest, exactly as before.
                        'enforced'         => $r->isEnforcing(),
                        'confirmed'        => $r->confirmed_at !== null,
                    ];
                })
                ->all();
        }
    }

    /**
     * The tenant's current policy version, as one number the agent can compare
     * against what it has stored.
     *
     * bundle['policy_version'] is the MONITORING policy's version and does not
     * move when app or website rules change — which is exactly why rule edits
     * never reached endpoints promptly. This one moves for any of them.
     */
    public function latestPolicyVersionFor(int $companyId): int
    {
        $appMax = (int) ApplicationPolicy::withoutGlobalScopes()
            ->where('company_id', $companyId)->max('version');
        $siteMax = (int) WebsitePolicy::withoutGlobalScopes()
            ->where('company_id', $companyId)->max('version');
        $ruleMax = (int) PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)->max('version');
        $stateMax = (int) EnforcementState::withoutGlobalScopes()
            ->where('company_id', $companyId)->max('policy_version');

        return max(1, $appMax + $siteMax + $ruleMax + $stateMax);
    }

    /**
     * Effective tracking mode for an employee (optionally on a device), walking
     * DEVICE > EMPLOYEE > TEAM > DEPARTMENT > BRANCH > COMPANY. First level that
     * sets a valid mode wins; nothing set anywhere = FULL.
     */
    public function effectiveTrackingMode(Employee $employee, ?EmployeeDevice $device = null, ?Company $company = null): string
    {
        $candidates = [];
        if ($device) { $candidates[] = $device->tracking_mode; }
        $candidates[] = $employee->tracking_mode;
        if ($employee->team_id)       { $candidates[] = optional(Team::withoutGlobalScopes()->find($employee->team_id))->tracking_mode; }
        if ($employee->department_id) { $candidates[] = optional(Department::withoutGlobalScopes()->find($employee->department_id))->tracking_mode; }
        if ($employee->branch_id)     { $candidates[] = optional(Branch::withoutGlobalScopes()->find($employee->branch_id))->tracking_mode; }
        $candidates[] = ($company ?? Company::find($employee->company_id))?->tracking_mode;

        foreach ($candidates as $m) {
            $m = strtoupper(trim((string) $m));
            if (in_array($m, ['FULL', 'PRESENCE_ONLY', 'EXCLUDED'], true)) {
                return $m;
            }
        }

        return 'FULL';
    }

    /**
     * Is THIS person enforced, on THIS device?
     *
     * Same six levels and the same most-specific-wins rule as
     * effectiveTrackingMode above, on purpose. Two settings that look alike and
     * resolve differently is how a console ends up with a rule nobody can
     * predict.
     *
     *     device -> employee -> SHIFT -> team -> department -> branch -> company
     *
     * SHIFT joined the chain on 27-Aug-2026 (Ejaz), between EMPLOYEE and TEAM. A shift is
     * chosen per person, so it is more specific than the team they belong to — and the request
     * it exists to serve is "the night shift may use the remote-support tool", which has to
     * beat "the support team is enforced" rather than lose to it. It is also the only level
     * where the SAME person is legitimately enforced at one hour and exempt at another, so
     * putting it below team would make the setting unusable for the case it was asked for.
     *
     * It sits BELOW the employee row on purpose: an exemption granted to one named person is
     * a deliberate act about that person, and must not be overridden by whichever shift they
     * are rostered onto next week.
     *
     * A dated exemption on the employee row beats everything, because that is
     * what a dated exemption is for: "Priya covers client calls this week" must
     * stop by itself on Friday. An exemption an administrator has to remember to
     * remove is an exemption that becomes permanent.
     *
     * Returning ENFORCED is NOT the same as blocking. The tenant-wide
     * EnforcementState still has to be ENFORCE before anything on any PC is refused.
     */
    public function effectiveEnforcementMode(Employee $employee, ?EmployeeDevice $device = null, ?Company $company = null): string
    {
        // A live, dated exemption wins outright.
        if ($this->exemptionIsLive($employee)) {
            return EnforcementMode::EXEMPT;
        }

        $candidates = [];
        if ($device) { $candidates[] = $device->enforcement_mode ?? null; }
        $candidates[] = $employee->enforcement_mode ?? null;
        if ($employee->shift_id)      { $candidates[] = optional(Shift::withoutGlobalScopes()->find($employee->shift_id))->enforcement_mode; }
        if ($employee->team_id)       { $candidates[] = optional(Team::withoutGlobalScopes()->find($employee->team_id))->enforcement_mode; }
        if ($employee->department_id) { $candidates[] = optional(Department::withoutGlobalScopes()->find($employee->department_id))->enforcement_mode; }
        if ($employee->branch_id)     { $candidates[] = optional(Branch::withoutGlobalScopes()->find($employee->branch_id))->enforcement_mode; }
        $candidates[] = ($company ?? Company::find($employee->company_id))?->enforcement_mode;

        foreach ($candidates as $m) {
            if ($clean = EnforcementMode::clean($m)) {
                return $clean;
            }
        }

        return EnforcementMode::DEFAULT;
    }

    /**
     * Is a dated exemption in force today?
     *
     * Both dates are inclusive, and either may be blank:
     *
     *     from blank, until blank   ->  not a dated exemption at all; the
     *                                   enforcement_mode column decides
     *     from set, until blank     ->  from that day onwards
     *     from blank, until set     ->  up to and including that day
     *
     * Compared in the COMPANY's timezone, not the server's. An exemption that
     * ends "on Friday" has to end on the client's Friday, or somebody in India
     * loses their exemption five and a half hours early.
     */
    private function exemptionIsLive(Employee $employee): bool
    {
        $from  = $employee->enforcement_exempt_from ?? null;
        $until = $employee->enforcement_exempt_until ?? null;

        if (! $from && ! $until) {
            return false;
        }

        $tz = optional(Company::find($employee->company_id))->timezone ?: config('app.timezone');
        $today = now($tz)->toDateString();

        if ($from && $today < \Illuminate\Support\Carbon::parse($from)->toDateString()) {
            return false;
        }
        if ($until && $today > \Illuminate\Support\Carbon::parse($until)->toDateString()) {
            return false;
        }

        return true;
    }

    /** Fold a non-FULL tracking mode back into the resolved policy flags. */
    private function applyTrackingMode(array &$policies, string $mode): void
    {
        if ($mode === 'FULL') {
            return;
        }

        if (isset($policies['monitoring']) && is_array($policies['monitoring'])) {
            $policies['monitoring']['app_usage_enabled'] = false;
            $policies['monitoring']['website_usage_enabled'] = false;
            if ($mode === 'EXCLUDED') {
                $policies['monitoring']['tracking_enabled'] = false;
            }
        }
        if (isset($policies['screenshot']) && is_array($policies['screenshot'])) {
            $policies['screenshot']['enabled'] = false;
        }
        if (isset($policies['webcam']) && is_array($policies['webcam'])) {
            $policies['webcam']['presence_enabled'] = false;
            $policies['webcam']['photo_enabled'] = false;
        }
    }

    /**
     * The ordered (type, id) candidates from most specific to least specific.
     */
    /**
     * Resolve ONE policy type for an employee to its stored attributes (or null when
     * nothing is assigned). Used by attendance derivation to read the effective
     * late-grace from the Attendance policy the admin set in the Policy tab (EPT25-01).
     */
    public function resolvePolicy(Employee $employee, string $type): ?array
    {
        $chain = $this->assignableChain($employee, null);
        $assignments = $this->assignmentsFor($employee->company_id, $chain);

        return $this->resolveType($type, $chain, $assignments, $employee);
    }

    private function assignableChain(Employee $employee, ?EmployeeDevice $device): array
    {
        $chain = [];
        if ($device) {
            $chain[] = ['DEVICE', $device->id];
        }
        $chain[] = ['EMPLOYEE', $employee->id];
        if ($employee->team_id)       { $chain[] = ['TEAM', $employee->team_id]; }
        if ($employee->department_id) { $chain[] = ['DEPARTMENT', $employee->department_id]; }
        if ($employee->branch_id)     { $chain[] = ['BRANCH', $employee->branch_id]; }
        $chain[] = ['COMPANY', $employee->company_id];

        return $chain;
    }

    /**
     * All currently-effective assignments for the company, indexed for quick lookup.
     */
    private function assignmentsFor(int $companyId, array $chain): array
    {
        $today = now()->toDateString();

        $rows = PolicyAssignment::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->get();

        // index[type][assignableType][assignableId] = policy_id
        $index = [];
        foreach ($rows as $r) {
            $index[$r->policy_type][$r->assignable_type][$r->assignable_id] = $r->policy_id;
        }

        return $index;
    }

    private function resolveType(string $type, array $chain, array $assignments, Employee $employee): ?array
    {
        $policyId = null;

        foreach ($chain as [$assignableType, $assignableId]) {
            if (isset($assignments[$type][$assignableType][$assignableId])) {
                $policyId = $assignments[$type][$assignableType][$assignableId];
                break;
            }
        }

        // Fallback: direct link stored on the employee record.
        if (! $policyId && $type === 'MONITORING' && $employee->monitoring_policy_id) {
            $policyId = $employee->monitoring_policy_id;
        }
        if (! $policyId && $type === 'COMPLIANCE' && $employee->compliance_policy_id) {
            $policyId = $employee->compliance_policy_id;
        }

        if (! $policyId) {
            return null;
        }

        $model = self::MODELS[$type];
        $policy = $model::withoutGlobalScopes()->find($policyId);

        return $policy ? $policy->toArray() : null;
    }

    /**
     * DIAGNOSTIC (read-only): explain WHY each monitored capability is on/off for one
     * employee — the effective value plus the policy and the precedence level (DEVICE /
     * EMPLOYEE / TEAM / DEPARTMENT / BRANCH / COMPANY / EMPLOYEE_LINK) that decided it, and
     * the resolved tracking mode + where it came from. Powers the employee "Policy" tab.
     */
    public function traceForEmployee(Employee $employee, ?EmployeeDevice $device = null): array
    {
        if (! $device) {
            $device = EmployeeDevice::withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->orderByDesc('last_heartbeat_at')->orderByDesc('id')->first();
        }

        $chain = $this->assignableChain($employee, $device);
        $assignments = $this->assignmentsFor($employee->company_id, $chain);

        // Final effective flags = exactly what the agent obeys (after tracking-mode folding).
        $bundle = $this->bundleForEmployee($employee, $device);
        $mon = $bundle['policies']['monitoring'] ?? [];
        $shot = $bundle['policies']['screenshot'] ?? [];
        $cam = $bundle['policies']['webcam'] ?? [];

        $cap = fn ($on, $type) => [
            'on'     => (bool) $on,
            'policy' => $this->sourceFor($type, $chain, $assignments, $employee),
        ];

        return [
            'device' => $device ? [
                'id' => $device->id, 'name' => $device->computer_name, 'tracking_mode' => $device->tracking_mode,
            ] : null,
            'tracking_mode' => [
                'value'  => $bundle['tracking_mode'] ?? 'FULL',
                'source' => $this->trackingModeSource($employee, $device),
            ],
            'capabilities' => [
                'tracking'      => $cap($mon['tracking_enabled'] ?? false, 'MONITORING'),
                'app_usage'     => $cap($mon['app_usage_enabled'] ?? false, 'MONITORING'),
                'website_usage' => $cap($mon['website_usage_enabled'] ?? false, 'MONITORING'),
                'screenshots'   => $cap($shot['enabled'] ?? false, 'SCREENSHOT'),
                'webcam'        => $cap($cam['presence_enabled'] ?? false, 'WEBCAM'),
            ],
        ];
    }

    /** The winning [level, policy_id, policy_name] for a type, mirroring resolveType() selection. */
    private function sourceFor(string $type, array $chain, array $assignments, Employee $employee): array
    {
        foreach ($chain as [$level, $id]) {
            if (isset($assignments[$type][$level][$id])) {
                $pid = $assignments[$type][$level][$id];

                return ['level' => $level, 'policy_id' => $pid, 'policy_name' => $this->policyName($type, $pid)];
            }
        }
        if ($type === 'MONITORING' && $employee->monitoring_policy_id) {
            return ['level' => 'EMPLOYEE_LINK', 'policy_id' => $employee->monitoring_policy_id,
                'policy_name' => $this->policyName($type, $employee->monitoring_policy_id)];
        }

        return ['level' => 'NONE', 'policy_id' => null, 'policy_name' => null];
    }

    private function policyName(string $type, $id): ?string
    {
        $model = self::MODELS[$type] ?? null;
        if (! $model || ! $id) {
            return null;
        }
        $p = $model::withoutGlobalScopes()->find($id);

        return $p->name ?? null;
    }

    /** Which precedence level set the effective tracking mode (mirrors effectiveTrackingMode). */
    private function trackingModeSource(Employee $e, ?EmployeeDevice $device): string
    {
        $cands = [];
        if ($device) { $cands[] = ['DEVICE', $device->tracking_mode]; }
        $cands[] = ['EMPLOYEE', $e->tracking_mode];
        if ($e->team_id)       { $cands[] = ['TEAM', optional(Team::withoutGlobalScopes()->find($e->team_id))->tracking_mode]; }
        if ($e->department_id) { $cands[] = ['DEPARTMENT', optional(Department::withoutGlobalScopes()->find($e->department_id))->tracking_mode]; }
        if ($e->branch_id)     { $cands[] = ['BRANCH', optional(Branch::withoutGlobalScopes()->find($e->branch_id))->tracking_mode]; }
        $cands[] = ['COMPANY', optional(Company::find($e->company_id))->tracking_mode];

        foreach ($cands as [$level, $m]) {
            $m = strtoupper(trim((string) $m));
            if (in_array($m, ['FULL', 'PRESENCE_ONLY', 'EXCLUDED'], true)) {
                return $level . ' = ' . $m;
            }
        }

        return 'DEFAULT = FULL';
    }
}
