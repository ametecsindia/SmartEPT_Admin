<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        // Section 10: server-enforced single active session per employee. Serialise
        // concurrent logins for this employee with a short lock so two PCs racing to
        // sign in cannot BOTH win, then deny if another PC already holds a live session.
        $staleMinutes = (int) config('smartept.session_stale_minutes', 10);
        $lock = Cache::lock('agent-login:emp:' . $employee->id, 10);
        try {
            $lock->block(5);
        } catch (\Throwable $e) {
            return response()->json(['error' => ['code' => 'LOGIN_BUSY', 'message' => 'Another sign-in is in progress — please try again.']], 409);
        }

        try {
            $activeOther = EmployeeDevice::where('employee_id', $employee->id)
                ->where('device_uuid', '!=', $data['device_uuid'])
                ->where('session_status', 'ACTIVE')
                ->whereNull('unbound_at')
                ->where('last_heartbeat_at', '>=', now()->subMinutes($staleMinutes))
                ->first();

            if ($activeOther) {
                $this->audit($request, 'LOGIN_DENIED_CONCURRENT', EmployeeDevice::class, $activeOther->id, [
                    'attempted_device' => $data['device_uuid'],
                    'active_device'     => $activeOther->device_uuid,
                ]);

                return response()->json([
                    'error' => [
                        'code' => 'SINGLE_SESSION_ACTIVE',
                        'message' => 'Your SmartEPT account is already active on another computer ('
                            . ($activeOther->computer_name ?: 'another PC') . '). Please log out from the other computer or contact your administrator.',
                    ],
                ], 409);
            }

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
                    'company_id'      => $employee->company_id,
                    'employee_id'     => $employee->id,
                    'current_status'  => 'ONLINE',
                    'agent_health'    => 'HEALTHY',
                    'registered_at'   => now(),
                    'last_heartbeat_at' => now(),
                    'session_status'  => 'ACTIVE',
                    'last_login_at'   => now(),
                    'force_logout_at' => null,
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

            // Section 10: this device is now THE session — retire every OTHER of the
            // employee's sessions (revoke their agent tokens so a resuming stale PC gets
            // 401 → login), so exactly one PC is ever active.
            $retired = EmployeeDevice::where('employee_id', $employee->id)
                ->where('device_uuid', '!=', $data['device_uuid'])
                ->where('session_status', 'ACTIVE')
                ->get();
            foreach ($retired as $o) {
                $o->employee?->user?->tokens()->where('name', 'device:' . $o->device_uuid)->delete();
                $o->update(['session_status' => 'LOGGED_OUT', 'current_status' => 'OFFLINE']);
            }
            if ($retired->isNotEmpty()) {
                $this->audit($request, 'SESSION_TAKEOVER', EmployeeDevice::class, $device->id, [
                    'retired' => $retired->pluck('device_uuid'),
                ]);
            }

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
        } finally {
            optional($lock)->release();
        }
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
            'client_time'    => ['nullable', 'date'],
        ]);

        $device = EmployeeDevice::where('device_uuid', $data['device_uuid'])->firstOrFail();

        // EPT-25: clock-skew tamper check. The agent stamps its local time; a large
        // gap from server time usually means the PC clock was changed to fake hours.
        $skew = isset($data['client_time'])
            ? (int) abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($data['client_time'])))
            : null;
        $health = ($skew !== null && $skew > 180) ? 'DEGRADED' : 'HEALTHY';

        $device->update([
            'current_status'    => (($data['status'] ?? 'ONLINE') === 'ACTIVE' ? 'ONLINE' : ($data['status'] ?? 'ONLINE')),
            'app_version'       => $data['app_version'] ?? $device->app_version,
            'service_version'   => $data['service_version'] ?? $device->service_version,
            'sync_pending_count' => $data['sync_pending'] ?? $device->sync_pending_count,
            'agent_health'      => $health,
            'last_heartbeat_at' => now(),
        ]);

        // Biometric Gate: piggyback the live gate state on every heartbeat so the
        // agent syncs continuously (Ejaz's rule — no hourly button, heartbeat-style).
        $gate = null;
        $gateStatus = null; // QA Phase 2 (A3): the {gate_required, open, reason} the agent enforces on

        if ($device->employee) {
            $svc = app(\App\Services\GateService::class);
            $gate = $svc->stateFor($device->employee);           // backward-compatible nested block
            $gateStatus = $svc->statusFor($device->employee);    // gate_required, open, message, reason
        }

        // Section 2: piggyback the currently-joinable meeting (participant + in window +
        // not cancelled) so the agent shows the Meeting button ONLY when authorised, and
        // hides it within one heartbeat of a cancellation or the scheduled end.
        $meeting = null;
        if ($device->employee) {
            $m = \App\Models\Meeting::currentJoinableFor($device->employee);
            if ($m) {
                $inSession = \App\Models\EmployeeMeetingSession::where('meeting_id', $m->id)
                    ->where('employee_id', $device->employee->id)
                    ->whereNull('actual_end_at')->exists();
                $meeting = [
                    'id'         => $m->id,
                    'title'      => $m->title,
                    'end_at'     => $m->end_at->toIso8601String(),
                    'in_session' => $inSession,
                ];
            }
        }

        // Admin #9: an APPROACHING meeting (reminder lead-time reached, not yet started)
        // so the agent can pop a reminder with a Join button for members + organiser.
        $meetingReminder = null;
        if ($device->employee) {
            $rm = \App\Models\Meeting::reminderDueFor($device->employee);
            if ($rm) {
                $meetingReminder = [
                    'id'                => $rm->id,
                    'title'             => $rm->title,
                    'start_at'          => $rm->start_at->toIso8601String(),
                    'starts_in_seconds' => max(0, (int) now()->diffInSeconds($rm->start_at, false)),
                ];
            }
        }

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'clock_skew_seconds' => $skew,
            'gate' => $gate,                 // nested {enabled,state,arrived,...} (unchanged)
            'gate_status' => $gateStatus,    // QA Phase 2 (A3): {gate_required, open, message, reason}
            'meeting' => $meeting,
            'meeting_reminder' => $meetingReminder, // Admin #9: approaching-meeting reminder
        ]);
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

    /**
     * POST /api/devices/{device}/force-logout — Section 10: end this device's agent
     * session now. Revokes its token (so the agent gets 401 on its next heartbeat and
     * returns to the login screen) WITHOUT freeing the licence seat or blocking
     * re-registration — the employee can sign in again on any PC.
     */
    public function forceLogout(Request $request, EmployeeDevice $device): JsonResponse
    {
        $device->employee?->user?->tokens()
            ->where('name', 'device:' . $device->device_uuid)->delete();

        $device->update([
            'session_status'  => 'FORCE_LOGOUT',
            'force_logout_at' => now(),
            'current_status'  => 'OFFLINE',
        ]);

        $this->audit($request, 'FORCE_LOGOUT', EmployeeDevice::class, $device->id, [
            'device_uuid' => $device->device_uuid,
        ]);

        return response()->json(['data' => $device->fresh()]);
    }

    /**
     * POST /api/agent/session-logout — the agent's own explicit sign-out. Marks the
     * session logged out and revokes the current token so another PC can take over
     * immediately (no wait for the stale window).
     */
    public function sessionLogout(Request $request): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('agent'), 403, 'Agent token required.');

        $uuid = (string) $request->input('device_uuid');
        $device = EmployeeDevice::where('device_uuid', $uuid)->first();
        if ($device) {
            $device->update(['session_status' => 'LOGGED_OUT', 'current_status' => 'OFFLINE']);
        }
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * PUT /api/devices/{device}/tracking-mode — set a per-device override
     * (null = inherit from the employee / team / department / branch / company).
     */
    public function trackingMode(Request $request, EmployeeDevice $device): JsonResponse
    {
        $data = $request->validate([
            'tracking_mode' => ['nullable', 'in:FULL,PRESENCE_ONLY,EXCLUDED'],
        ]);

        $device->update(['tracking_mode' => $data['tracking_mode'] ?? null]);
        $this->audit($request, 'DEVICE_TRACKING_MODE', EmployeeDevice::class, $device->id, $data);

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
