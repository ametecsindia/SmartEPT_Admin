<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\InstallationLicense;
use App\Models\LiveViewSession;
use App\Services\LiveViewTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SmartEPT LiveView — Phase 4 control plane. Decides WHETHER a stream may
 * start and issues the two short-lived signed tokens; never touches a frame.
 *
 * Phase 4 (14-Sep-2026) added: permission slugs (routes/api.php + the
 * liveview.high_quality check below), a (since superseded) concurrency/licence
 * gate, and the heartbeat-timeout sweep (App\Console\Commands\EndStaleLiveViewSessions) —
 * see 2026_09_14_000100_seed_liveview_permissions.php's docblock.
 *
 * Phase 5 (21-Sep-2026, Ejaz): licensing switched from "how many screens may be
 * open at once" to "how many employees may be granted LiveView permission" —
 * see the permission gate in start(), and permissions()/setPermission() below.
 */
class LiveViewController extends Controller
{
    public function __construct(private LiveViewTokenService $tokens)
    {
    }

    /** POST /api/liveview/session/start */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'   => ['required', 'integer'],
            'monitor_index' => ['nullable', 'integer', 'min:0', 'max:15'],
            'quality'       => ['nullable', 'in:data_saver,low,high'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);

        $device = EmployeeDevice::where('employee_id', $employee->id)
            ->orderByDesc('last_heartbeat_at')
            ->first();

        $onlineWindow = (int) config('liveview.device_online_window_seconds');
        $online = $device && $device->last_heartbeat_at
            && $device->last_heartbeat_at->greaterThan(now()->subSeconds($onlineWindow));

        if (! $online) {
            return response()->json([
                'error' => ['code' => 'AGENT_OFFLINE', 'message' => 'This employee\'s Agent is not currently reachable.'],
            ], 409);
        }

        $quality = $data['quality'] ?? 'low';
        if ($quality === 'high' && ! $request->user()->hasPermission('liveview.high_quality')) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Missing permission: liveview.high_quality.'],
            ], 403);
        }

        // Permission gate (Phase 5, 21-Sep-2026, Ejaz): licensing is no longer a
        // simultaneous-viewing cap — any number of permitted employees may be
        // watched at once. The licensed resource is now the GRANT itself (see
        // setPermission()'s own limit check, against bundle['features']['liveview_max_users']).
        // An employee without the grant is never startable, full stop.
        if (! $employee->liveview_enabled) {
            return response()->json([
                'error' => ['code' => 'LIVEVIEW_NOT_PERMITTED', 'message' => 'This employee has not been granted LiveView permission. Grant it from Manage LiveView Permissions first.'],
            ], 403);
        }

        $session = LiveViewSession::create([
            'admin_user_id' => $request->user()->id,
            'employee_id'   => $employee->id,
            'device_uuid'   => $device->device_uuid,
            'monitor_index' => $data['monitor_index'] ?? 0,
            'quality'       => $quality,
            'status'        => 'connecting',
            'started_at'    => now(),
            'last_heartbeat_at' => now(),
            'admin_ip'      => $request->ip(),
        ]);

        $this->audit($request, 'liveview.session_start', LiveViewSession::class, $session->id, [
            'employee_id' => $employee->id,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'view_token' => $this->tokens->mint($session->id, 'view'),
            'relay_url'  => LiveViewSession::relayUrl(),
        ], 201);
    }

    /** POST /api/liveview/session/{id}/stop */
    public function stop(Request $request, LiveViewSession $session): JsonResponse
    {
        if (! $session->isActive()) {
            return response()->json(['ok' => true]); // already ended — stopping twice is not an error
        }

        $session->forceFill([
            'status' => 'ended',
            'ended_at' => now(),
            'termination_reason' => 'admin_closed',
        ])->save();

        $this->audit($request, 'liveview.session_stop', LiveViewSession::class, $session->id);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/liveview/permissions — the management list for the new permission-
     * based licence (Phase 5, 21-Sep-2026): every employee plus whether they're
     * currently granted LiveView permission, and the licence's cap on how many
     * may be granted at once. `used` never exceeds `limit` going forward, but an
     * already-over-limit company (a licence downgrade) keeps every existing
     * grant — same "not retrospective" rule LicenceSeats already uses for seats.
     */
    public function permissions(Request $request): JsonResponse
    {
        $limit = InstallationLicense::governing($request->user()->company)
            ->bundle['features']['liveview_max_users'] ?? 0;

        $employees = Employee::whereNull('deleted_at')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'liveview_enabled']);

        return response()->json([
            'limit'     => $limit,
            'used'      => $employees->where('liveview_enabled', true)->count(),
            'employees' => $employees,
        ]);
    }

    /**
     * POST /api/liveview/permission — grant or revoke one employee's LiveView
     * permission. This grant is the licensed resource now, capped by
     * bundle['features']['liveview_max_users'] — checked only when granting;
     * revoking always succeeds.
     */
    public function setPermission(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'enabled'     => ['required', 'boolean'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);

        if ($data['enabled'] && ! $employee->liveview_enabled) {
            $limit = InstallationLicense::governing($request->user()->company)
                ->bundle['features']['liveview_max_users'] ?? 0;
            $used = Employee::where('liveview_enabled', true)->count();
            if ($used >= $limit) {
                return response()->json([
                    'error' => ['code' => 'LIVEVIEW_LIMIT_REACHED', 'message' => "Your licence allows LiveView permission for {$limit} " . str('user')->plural($limit) . '. Revoke one before granting another, or buy more LiveView seats.'],
                ], 409);
            }
        }

        $employee->forceFill(['liveview_enabled' => (bool) $data['enabled']])->save();

        $this->audit($request, 'liveview.permission_' . ($data['enabled'] ? 'grant' : 'revoke'), Employee::class, $employee->id);

        return response()->json(['ok' => true, 'liveview_enabled' => $employee->liveview_enabled]);
    }

    /**
     * GET /api/agent/liveview/poll — 15-Sep-2026: a dedicated, cheap, fast-cadence
     * sibling to the ~30s device heartbeat (see DeviceController::liveviewRequestedFor()'s
     * docblock). One indexed lookup, no policy/enforcement computation, so it's safe
     * for the Agent to hit every few seconds — that's what makes Start (and restart
     * after Stop) feel immediate instead of "stuck" for up to 30s.
     */
    public function poll(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('agent'), 403, 'Agent token required.');

        $deviceUuid = (string) $request->query('device_uuid', '');
        // 15-Sep-2026: every connecting session for this device, not just one — see
        // LiveViewSession::connectingFor()'s docblock (two monitors started for the
        // same employee used to starve all but the newest).
        $sessions = $deviceUuid !== '' ? LiveViewSession::connectingFor($deviceUuid) : collect();

        return response()->json([
            'liveview_requested' => $sessions->map(fn ($s) => $s->toAgentRequest($this->tokens))->all(),
        ]);
    }
}
