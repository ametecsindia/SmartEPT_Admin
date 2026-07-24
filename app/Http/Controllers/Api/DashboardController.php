<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ScopesVisibleEmployees;
use App\Support\ResolvesBusinessDay;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\EmployeePresenceEvent;
use App\Models\EmployeeScreenshotLog;
use App\Services\StatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ScopesVisibleEmployees;
    use ResolvesBusinessDay;
    /** GET /api/dashboard/live-status — cards + employee live table (tenant-scoped). */
    public function liveStatus(Request $request): JsonResponse
    {
        $tz = $this->bizTz($request);
        $today = $this->bizToday($tz);
        $day = $this->dayUtcBounds($today, $tz);
        $onlineWindow = now()->subMinutes(3);
        $visible = $this->scopedEmployeeIds($request);

        $devices = EmployeeDevice::query()->get();

        $activeSecs = EmployeeActivityEvent::query()
            ->whereDate('started_at', $today)->where('event_type', 'ACTIVE')   // EPT-20: agent stores LOCAL time
            ->select('employee_id', DB::raw('SUM(duration_seconds) as s'))
            ->groupBy('employee_id')->pluck('s', 'employee_id');
        $idleSecs = EmployeeActivityEvent::query()
            ->whereDate('started_at', $today)->where('event_type', 'IDLE')   // EPT-20: agent stores LOCAL time
            ->select('employee_id', DB::raw('SUM(duration_seconds) as s'))
            ->groupBy('employee_id')->pluck('s', 'employee_id');

        $onBreak = EmployeeBreakLog::whereNull('end_at')->whereDate('start_at', $today)
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->distinct()->count('employee_id');

        // R4 item 7 + QA Phase 1 B1/B2/B3/B15: employee-centric status, ONE source of truth
        // for cards AND rows so the numbers always agree with the table. Device heartbeat
        // decides ONLINE vs OFFLINE (an instant-logout flip is offline even with a recent
        // heartbeat); the authoritative status_timeline then decides WHICH working state an
        // online employee is in — active / idle / on a specific break / in a meeting — so an
        // employee falls into exactly one category and can never be "active AND on break".
        $devByEmp = $devices->keyBy('employee_id');

        $employees = Employee::with(['team:id,name', 'department:id,name'])
            ->where('employment_status', 'ACTIVE')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->get();

        // Self-heal any stale "ghost" break/meeting left open from a previous day BEFORE we
        // read the live status, so nobody shows as "On break · 16h" (agent killed mid-break).
        app(StatusService::class)->closeStaleOpenSegments(
            $employees->pluck('id')->all(),
            \Illuminate\Support\Carbon::parse($today)->startOfDay()
        );

        $openMap = app(StatusService::class)->openStatusMap($employees->pluck('id')->all());
        $now = now();

        $rows = $employees->map(function ($e) use ($devByEmp, $activeSecs, $idleSecs, $onlineWindow, $openMap, $now) {
            $d = $devByEmp->get($e->id);
            $fresh = $d && $d->last_heartbeat_at && $d->last_heartbeat_at->gte($onlineWindow);
            $status = (! $fresh || $d->current_status === 'OFFLINE')
                ? 'OFFLINE'
                : ($d->current_status ?: 'ONLINE');

            // Timeline-derived working status (one category per employee).
            $seg = $openMap[$e->id] ?? null;
            $breakStart = null;
            if ($status === 'OFFLINE') {
                $work = 'OFFLINE';                        // offline never counts as active/idle/break/meeting
            } elseif ($seg) {
                $work = $seg['state'] === 'LOGGED_IN' ? 'ACTIVE' : $seg['state'];
                if (in_array($work, ['TEA_BREAK', 'LUNCH_BREAK', 'OTHER_BREAK', 'MEETING'], true)) {
                    $breakStart = $seg['started_at'];
                }
            } else {
                // No timeline segment yet (rollout / never-transitioned) — fall back to the
                // device's coarse status so nobody vanishes from the board.
                $work = match ($status) {
                    'IDLE', 'AWAY' => 'IDLE',
                    default        => 'ACTIVE',
                };
            }

            return [
                'employee_id'    => $e->id,
                'name'           => $e->fullName(),
                'department'     => $e->department?->name,
                'team'           => $e->team?->name,
                'status'         => $status,                       // device status (backward compatible)
                'work_status'    => $work,                         // timeline-derived working state
                'break_started_at' => $breakStart,
                'elapsed'        => $breakStart ? max(0, $now->getTimestamp() - $breakStart->getTimestamp()) : null,
                'last_seen'      => $d?->last_heartbeat_at,
                'active_seconds' => (int) ($activeSecs[$e->id] ?? 0),
                'idle_seconds'   => (int) ($idleSecs[$e->id] ?? 0),
                'compliance_status' => $d?->compliance_status,
            ];
        });

        $byStatus = fn ($s) => $rows->where('status', $s)->count();
        $byWork = fn ($s) => $rows->where('work_status', $s)->count();
        $breakTea = $byWork('TEA_BREAK');
        $breakLunch = $byWork('LUNCH_BREAK');
        $breakOther = $byWork('OTHER_BREAK');

        $cards = [
            'total_employees'   => $rows->count(),
            // Legacy device-status cards (kept so existing consumers don't break).
            'active_now'        => $byStatus('ONLINE'),
            'idle_now'          => $byStatus('IDLE'),
            'away_now'          => $byStatus('AWAY'),
            'offline'           => $byStatus('OFFLINE'),
            'on_break'          => $onBreak,
            // QA Phase 1 timeline-derived cards (one employee → exactly one of these).
            'active'            => $byWork('ACTIVE'),
            'idle'              => $byWork('IDLE'),
            'break_total'       => $breakTea + $breakLunch + $breakOther,
            'break_tea'         => $breakTea,
            'break_lunch'       => $breakLunch,
            'break_other'       => $breakOther,
            'meeting'           => $byWork('MEETING'),
            'offline_count'     => $byWork('OFFLINE'),
            'camera_blocked'    => EmployeePresenceEvent::whereDate('started_at', $today)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->where('event_type', 'CAMERA_BLOCKED')->distinct()->count('employee_id'),
            'violations_today'  => EmployeeComplianceEvent::whereDate('started_at', $today)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'screenshots_today' => EmployeeScreenshotLog::whereDate('captured_at', $today)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
        ];

        return response()->json(['cards' => $cards, 'employees' => $rows->values()]);
    }

    /** GET /api/dashboard/summary — headline counts for a date. */
    public function summary(Request $request): JsonResponse
    {
        $tz = $this->bizTz($request);
        $date = $request->query('date', $this->bizToday($tz));
        $day = $this->dayUtcBounds($date, $tz);
        $visible = $this->visibleEmployeeIds($request->user());

        return response()->json([
            'date'              => $date,
            'employees'         => Employee::where('employment_status', 'ACTIVE')->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->count(),
            'devices'           => EmployeeDevice::when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'violations'        => EmployeeComplianceEvent::whereDate('started_at', $date)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'screenshots'       => EmployeeScreenshotLog::whereDate('captured_at', $date)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'active_hours_total' => round(((int) EmployeeActivityEvent::whereDate('started_at', $date)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->where('event_type', 'ACTIVE')->sum('duration_seconds')) / 3600, 1),
        ]);
    }

    /** GET /api/dashboard/device-health — agent + device health overview. */
    public function deviceHealth(Request $request): JsonResponse
    {
        $visible = $this->scopedEmployeeIds($request);
        $devices = EmployeeDevice::with('employee:id,first_name,last_name,employee_code')
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->orderByDesc('last_heartbeat_at')
            ->get(['id', 'employee_id', 'device_uuid', 'computer_name', 'os_version', 'app_version', 'agent_health', 'compliance_status', 'current_status', 'sync_pending_count', 'last_heartbeat_at', 'last_sync_at']);

        // EPT-25: agent-stopped alert. A force-killed agent stops sending heartbeats
        // but its stored agent_health stays at the last value ('HEALTHY'). Flip it to
        // STOPPED at read-time when the last heartbeat is stale (>10 min) so the admin
        // sees the gap immediately. (Read-only override — not persisted.)
        $staleAfter = now()->subMinutes(10);
        $devices->each(function ($d) use ($staleAfter) {
            if (! $d->last_heartbeat_at || $d->last_heartbeat_at->lt($staleAfter)) {
                $d->agent_health = 'STOPPED';
            }
        });

        return response()->json(['data' => $devices]);
    }
}
