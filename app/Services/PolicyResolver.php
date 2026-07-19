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
use App\Models\MonitoringPolicy;
use App\Models\NetworkPolicy;
use App\Models\PolicyAssignment;
use App\Models\ScreenshotPolicy;
use App\Models\UsbPolicy;
use App\Models\VpnProxyPolicy;
use App\Models\WebcamPolicy;
use App\Models\WebsitePolicy;
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

        return [
            'employee_id'     => $employee->id,
            'device_uuid'     => $device?->device_uuid,
            'company_id'      => $employee->company_id,
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
}
