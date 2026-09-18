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
 * liveview.high_quality check below), the concurrency/licence gate below, and
 * the heartbeat-timeout sweep (App\Console\Commands\EndStaleLiveViewSessions) —
 * see 2026_09_14_000100_seed_liveview_permissions.php's docblock.
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

        // Concurrency/licence gate (Phase 4): a different kind of limit than
        // LicenceSeats' registered employee/user/device seats, so it isn't forced
        // through that service — it reads the bundle's own feature limit, same
        // place LicenseController::payload() already reads bundle['features'].
        $company = $request->user()->company;
        // 18-Sep-2026: default changed 1 → 0 — Live View is a Commander-only
        // feature now (see InstallationLicense::hasFeature('live_view')); a
        // licence bundle that carries no liveview_max_concurrent at all must
        // resolve to "no concurrent sessions", not "one for free".
        $limit = InstallationLicense::governing($company)->bundle['features']['liveview_max_concurrent'] ?? 0;
        // ponytail: nothing ever flips a session to 'live' (Phase 3 POC left that out), and
        // the heartbeat that keeps last_heartbeat_at fresh is the Agent's own device heartbeat
        // — which keeps firing even after the admin just closes the browser tab without
        // clicking Stop. So a 'connecting' row can outlive its viewer indefinitely and the
        // timeout sweep never catches it (device stays online). Found live 14-Sep-2026: an
        // abandoned POC session blocked every new Start. Fix: a 'connecting' row only holds
        // its concurrency slot for 2 minutes — long enough for the Agent's normal ~30s
        // heartbeat to pick up the request — after that it's presumed abandoned and stops
        // counting, even though the row itself stays open until the sweep or an explicit Stop
        // closes it. Upgrade path if this isn't enough: a real viewer-side pulse endpoint so
        // last_heartbeat_at reflects "someone is watching", not just "device is online".
        $activeCount = LiveViewSession::where('status', '!=', 'ended')
            ->whereNull('ended_at')
            ->where(function ($q) {
                $q->where('status', 'live')->orWhere('started_at', '>', now()->subMinutes(2));
            })
            ->count();
        if ($activeCount >= $limit) {
            return response()->json([
                'error' => ['code' => 'LIVEVIEW_LIMIT_REACHED', 'message' => "Your licence allows {$limit} concurrent LiveView " . str('session')->plural($limit) . '. Stop one before starting another.'],
            ], 409);
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
