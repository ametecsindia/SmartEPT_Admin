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
        return response()->json(['data' => $model::query()->latest('id')->get()]);
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

        return response()->json($resolver->bundleForEmployee($employee, $device));
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
