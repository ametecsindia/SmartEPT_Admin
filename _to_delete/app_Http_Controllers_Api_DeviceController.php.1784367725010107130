<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /** GET /api/devices — admin/manager view of registered endpoints (tenant-scoped). */
    public function index(Request $request): JsonResponse
    {
        $devices = EmployeeDevice::query()
            ->with('employee:id,first_name,last_name,employee_code,team_id')
            ->when($request->status, fn ($q, $v) => $q->where('current_status', $v))
            ->latest('last_heartbeat_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json($devices);
    }

    /**
     * POST /api/agent/register-device
     * Binds the calling employee's account to a hardware device_uuid and returns a
     * device-scoped token the agent uses for all subsequent agent endpoints.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid'          => ['required', 'string', 'max:191'],
            'computer_name'        => ['nullable', 'string', 'max:191'],
            'os_version'           => ['nullable', 'string', 'max:191'],
            'windows_username'     => ['nullable', 'string', 'max:191'],
            'lan_ip'               => ['nullable', 'string', 'max:64'],
            'public_ip'            => ['nullable', 'string', 'max:64'],
            'mac_address'          => ['nullable', 'string', 'max:64'],
            'wifi_ssid'            => ['nullable', 'string', 'max:191'],
            'processor'            => ['nullable', 'string', 'max:191'],
            'ram_gb'               => ['nullable', 'integer'],
            'disk_gb'              => ['nullable', 'integer'],
            'camera_available'     => ['nullable', 'boolean'],
            'microphone_available' => ['nullable', 'boolean'],
            'app_version'          => ['nullable', 'string', 'max:32'],
            'service_version'      => ['nullable', 'string', 'max:32'],
        ]);

        $employee = $this->resolveEmployee($request);

        // R2-1 seat enforcement: a brand-new device claims a licence seat on Central.
        // Re-registration of a known device never re-claims. Central down → allowed
        // (offline-tolerant); the daily validate reconciles seats.
        $existing = EmployeeDevice::where('device_uuid', $data['device_uuid'])->first();

        // R2-3: a device an admin unbound may not silently re-register.
        if ($existing && $existing->unbound_at) {
            return response()->json([
                'error' => [
                    'code' => 'DEVICE_UNBOUND',
                    'message' => 'This device was unbound by an administrator. Ask them to approve a re-bind on the Devices screen.',
                ],
            ], 409);
        }

        $isNewDevice = $existing === null;

        if ($isNewDevice) {
            $seat = app(\App\Services\LicenseClient::class)
                ->activateDevice($data['device_uuid'], $data['computer_name'] ?? null);

            if (! $seat['ok']) {
                return response()->json([
                    'error' => [
                        'code' => 'LICENSE_SEAT_BLOCKED',
                        'reason' => $seat['reason'],
                        'message' => $seat['reason'] === 'device_limit_reached'
                            ? 'All licensed device seats are in use. Free a seat (offboard/unbind a device) or upgrade the licence.'
                            : 'This device cannot be activated on the current licence (' . $seat['reason'] . ').',
                    ],
                ], 409);
            }
        }

        $device = EmployeeDevice::updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            array_merge($data, [
                'company_id'   => $employee->company_id,
                'employee_id'  => $employee->id,
                'current_status' => 'ONLINE',
                'agent_health'   => 'HEALTHY',
                'registered_at'  => now(),
                'last_heartbeat_at' => now(),
            ])
        );

        // Issue a device-scoped token (ability 'agent') on the employee's user account.
        $user = $request->user();
        $ttl = config('smartept.agent_token_ttl');
        $token = $user->createToken(
            'device:' . $data['device_uuid'],
            ['agent'],
            $ttl ? now()->addMinutes($ttl) : null
        )->plainTextToken;

        $device->forceFill(['device_token_hash' => hash('sha256', $token)])->save();
        $this->audit($request, 'REGISTER_DEVICE', EmployeeDevice::class, $device->id, ['device_uuid' => $device->device_uuid]);

        return response()->json([
            'device_token' => $token,
            'device'       => $device,
            'employee'     => [
                'id'   => $employee->id,
                'name' => $employee->fullName(),
                'code' => $employee->employee_code,
            ],
        ], 201);
    }

    /**
     * POST /api/agent/heartbeat
     * 30-second liveness ping. Requires the 'agent' token ability.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('agent'), 403, 'Agent token required.');

        $data = $request->validate([
            'device_uuid'    => ['required', 'string'],
            'status'         => ['nullable', 'in:ONLINE,ACTIVE,IDLE,AWAY,OFFLINE'],
            'app_version'    => ['nullable', 'string', 'max:32'],
            'service_version' => ['nullable', 'string', 'max:32'],
            'sync_pending'   => ['nullable', 'integer', 'min:0'],
        ]);

        $device = EmployeeDevice::where('device_uuid', $data['device_uuid'])->firstOrFail();

        $device->update([
            'current_status'    => (($data['status'] ?? 'ONLINE') === 'ACTIVE' ? 'ONLINE' : ($data['status'] ?? 'ONLINE')),
            'app_version'       => $data['app_version'] ?? $device->app_version,
            'service_version'   => $data['service_version'] ?? $device->service_version,
            'sync_pending_count' => $data['sync_pending'] ?? $device->sync_pending_count,
            'agent_health'      => 'HEALTHY',
            'last_heartbeat_at' => now(),
        ]);

        // Biometric Gate: piggyback the live gate state on every heartbeat so the
        // agent syncs continuously (Ejaz's rule — no hourly button, heartbeat-style).
        $gate = null;

        if ($device->employee) {
            $gate = app(\App\Services\GateService::class)->stateFor($device->employee);
        }

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String(), 'gate' => $gate]);
    }

    /**
     * POST /api/devices/{device}/unbind — R2-3: kill the device's agent token,
     * release its licence seat on Central and block re-registration until an
     * admin approves a re-bind.
     */
    public function unbind(Request $request, EmployeeDevice $device): JsonResponse
    {
        // Revoke exactly this device's agent token (named on creation).
        $device->employee?->user?->tokens()
            ->where('name', 'device:' . $device->device_uuid)->delete();

        app(\App\Services\LicenseClient::class)->deactivateDevice($device->device_uuid);

        $device->update([
            'unbound_at' => now(),
            'current_status' => 'OFFLINE',
            'agent_health' => 'STOPPED',
        ]);

        $this->audit($request, 'UNBIND_DEVICE', EmployeeDevice::class, $device->id, [
            'device_uuid' => $device->device_uuid,
        ]);

        return response()->json(['data' => $device->fresh()]);
    }

    /**
     * POST /api/devices/{device}/rebind — approve a previously unbound device.
     * Re-claims a licence seat first; if none is free the admin sees why.
     */
    public function rebind(Request $request, EmployeeDevice $device): JsonResponse
    {
        $seat = app(\App\Services\LicenseClient::class)
            ->activateDevice($device->device_uuid, $device->computer_name);

        if (! $seat['ok']) {
            return response()->json([
                'error' => [
                    'code' => 'LICENSE_SEAT_BLOCKED',
                    'reason' => $seat['reason'],
                    'message' => $seat['reason'] === 'device_limit_reached'
                        ? 'No free licence seat for this device — unbind another device or upgrade the licence.'
                        : 'Central refused this device (' . $seat['reason'] . ').',
                ],
            ], 409);
        }

        $device->update(['unbound_at' => null]);

        $this->audit($request, 'REBIND_DEVICE', EmployeeDevice::class, $device->id, [
            'device_uuid' => $device->device_uuid,
        ]);

        return response()->json(['data' => $device->fresh()]);
    }

    private function resolveEmployee(Request $request): Employee
    {
        $user = $request->user();

        // Prefer the employee linked to the authenticated user.
        $employee = Employee::where('user_id', $user->id)->first();

        // Admins may register on behalf by passing employee_code.
        if (! $employee && $request->filled('employee_code')) {
            $employee = Employee::where('employee_code', $request->input('employee_code'))->first();
        }

        abort_if(! $employee, 422, 'No employee is linked to this account.');

        return $employee;
    }
}
