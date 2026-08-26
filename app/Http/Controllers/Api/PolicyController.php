<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPolicy;
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
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Team;
use App\Services\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    private const MODELS = [
        'monitoring'  => MonitoringPolicy::class,
        'screenshot'  => ScreenshotPolicy::class,
        'webcam'      => WebcamPolicy::class,
        'application' => ApplicationPolicy::class,
        'website'     => WebsitePolicy::class,
        'network'     => NetworkPolicy::class,
        'device'      => DevicePolicy::class,
        'usb'         => UsbPolicy::class,
        'vpn_proxy'   => VpnProxyPolicy::class,
        'break'       => BreakPolicy::class,
        'attendance'  => AttendancePolicy::class,
        'compliance'  => CompliancePolicy::class,
    ];

    /** GET /api/policies/{type} */
    public function index(string $type): JsonResponse
    {
        $model = $this->model($type);
        $query = $model::query()->latest('id');

        // Application and website policies carry per-item rules in their own
        // table. The Rules screen needs them in the same payload, or it has
        // nothing to restore each row's action from.
        if (in_array($type, ['application', 'website'], true)) {
            $query->with('rules');
        }

        return response()->json(['data' => $query->get()]);
    }

    /** POST /api/policies/{type} */
    public function store(Request $request, string $type): JsonResponse
    {
        $model = $this->model($type);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']] + $this->passthroughRule());
        $payload = array_merge($request->except(['company_id', 'id']), ['name' => $data['name'], 'version' => 1]);

        $policy = $model::create($payload);
        $this->audit($request, 'CREATE', $model, $policy->id, ['name' => $policy->name]);

        return response()->json(['data' => $policy], 201);
    }

    /** PUT /api/policies/{type}/{id} — editing bumps the version so agents re-fetch. */
    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->model($type);
        $policy = $model::findOrFail($id);

        $payload = $request->except(['company_id', 'id', 'version']);
        $payload['version'] = (int) $policy->version + 1;

        $policy->update($payload);
        $this->audit($request, 'UPDATE', $model, $policy->id, ['version' => $policy->version]);

        return response()->json(['data' => $policy]);
    }

    /** DELETE /api/policies/{type}/{id} */
    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->model($type);
        $policy = $model::findOrFail($id);
        $policy->delete();
        $this->audit($request, 'DELETE', $model, $id);

        return response()->json(null, 204);
    }

    /** POST /api/policies/assign — attach a policy to an org level / employee / device. */
    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'policy_type'     => ['required', 'in:MONITORING,SCREENSHOT,WEBCAM,APPLICATION,WEBSITE,NETWORK,DEVICE,USB,VPN_PROXY,BREAK,ATTENDANCE,COMPLIANCE'],
            'policy_id'       => ['required', 'integer'],
            'assignable_type' => ['required', 'in:COMPANY,BRANCH,DEPARTMENT,TEAM,EMPLOYEE,DEVICE'],
            'assignable_id'   => ['required', 'integer'],
            'effective_from'  => ['nullable', 'date'],
            'effective_to'    => ['nullable', 'date'],
        ]);

        // Tenant safety (R5 EPT-05): the policy AND the target must belong to the
        // caller's company. Policy/org models carry the BelongsToCompany global scope,
        // so findOrFail() 404s on a cross-tenant id; Super Admin bypasses the scope.
        $policyClass = self::MODELS[strtolower($data['policy_type'])];
        $policyClass::findOrFail($data['policy_id']);

        if ($data['assignable_type'] === 'COMPANY') {
            abort_unless(
                $request->user()->isSuperAdmin()
                    || (int) $data['assignable_id'] === (int) $request->user()->company_id,
                403, 'Cannot assign a policy to another company.'
            );
        } else {
            $targets = [
                'BRANCH'     => Branch::class,
                'DEPARTMENT' => Department::class,
                'TEAM'       => Team::class,
                'EMPLOYEE'   => Employee::class,
                'DEVICE'     => EmployeeDevice::class,
            ];
            $targets[$data['assignable_type']]::findOrFail($data['assignable_id']);
        }

        $assignment = PolicyAssignment::create(array_merge($data, [
            'assigned_by_user_id' => $request->user()->id,
        ]));
        $this->audit($request, 'ASSIGN', PolicyAssignment::class, $assignment->id, $data);

        return response()->json(['data' => $assignment], 201);
    }

    /**
     * GET /api/agent/policy — the composed policy bundle for the calling agent.
     * Resolves the employee from the authenticated account and (optionally) the device.
     */
    public function agentBundle(Request $request, PolicyResolver $resolver): JsonResponse
    {
        abort_unless($request->user()->tokenCan('agent'), 403, 'Agent token required.');

        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        $device = null;
        if ($request->filled('device_uuid')) {
            $device = EmployeeDevice::where('device_uuid', $request->query('device_uuid'))->first();
        }

        $bundle = $resolver->bundleForEmployee($employee, $device);

        // Section 3: hand the agent this company's break-time limits (seconds) so it can
        // enforce the mandatory over-limit reason locally — works even while offline.
        // "Other" is stored as break_type CUSTOM; expose both keys so the agent maps cleanly.
        $company = Company::withoutGlobalScopes()->find($employee->company_id);
        if (is_array($bundle) && $company) {
            $bundle['break_limits'] = [
                'LUNCH'  => (int) ($company->break_limit_lunch_min ?? 30) * 60,
                'TEA'    => (int) ($company->break_limit_tea_min ?? 10) * 60,
                'OTHER'  => (int) ($company->break_limit_other_min ?? 10) * 60,
                'CUSTOM' => (int) ($company->break_limit_other_min ?? 10) * 60,
            ];

            // QA Phase 2 (A8): exit/uninstall lock. The agent receives ONLY a SHA-256 of
            // the admin password so it can verify a typed one locally (offline too); the
            // plaintext never leaves the server.
            $bundle['agent'] = [
                'exit_lock_enabled'    => (bool) ($company->agent_exit_lock_enabled ?? false),
                'exit_password_sha256' => filled($company->agent_exit_password)
                    ? hash('sha256', (string) $company->agent_exit_password)
                    : null,
            ];
        }

        return response()->json($bundle);
    }

    private function model(string $type): string
    {
        abort_unless(isset(self::MODELS[$type]), 404, "Unknown policy type: {$type}");
        return self::MODELS[$type];
    }

    private function passthroughRule(): array
    {
        // Policy bodies are permissive: each policy model guards only 'id' and casts its
        // own JSON/boolean fields, so we accept the remaining attributes as-is.
        return [];
    }
}
