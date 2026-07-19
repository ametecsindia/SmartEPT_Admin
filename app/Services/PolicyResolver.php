<?php

namespace App\Services;

use App\Models\ApplicationPolicy;
use App\Models\Company;
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

        return [
            'employee_id'     => $employee->id,
            'device_uuid'     => $device?->device_uuid,
            'company_id'      => $employee->company_id,
            'consent_required' => (bool) ($monitoring['consent_required'] ?? true),
            'policy_version'  => (int) ($monitoring['version'] ?? 1),
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
