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
            ->whereBetween('started_at', $day)->where('event_type', 'ACTIVE')
            ->select('employee_id', DB::raw('SUM(duration_seconds) as s'))
            ->groupBy('employee_id')->pluck('s', 'employee_id');
        $idleSecs = EmployeeActivityEvent::query()
            ->whereBetween('started_at', $day)->where('event_type', 'IDLE')
            ->select('employee_id', DB::raw('SUM(duration_seconds) as s'))
            ->groupBy('employee_id')->pluck('s', 'employee_id');

        $onBreak = EmployeeBreakLog::whereNull('end_at')->whereBetween('start_at', $day)
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->distinct()->count('employee_id');

        // R4 item 7: employee-centric status, ONE source of truth for cards AND rows
        // so the numbers always agree with the table. A device that reported OFFLINE
        // (instant logout flip) is offline even while its last heartbeat is recent.
        $devByEmp = $devices->keyBy('employee_id');
        $rows = Employee::with(['team:id,name', 'department:id,name'])
            ->where('employment_status', 'ACTIVE')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->get()
            ->map(function ($e) use ($devByEmp, $activeSecs, $idleSecs, $onlineWindow) {
                $d = $devByEmp->get($e->id);
                $fresh = $d && $d->last_heartbeat_at && $d->last_heartbeat_at->gte($onlineWindow);
                $status = (! $fresh || $d->current_status === 'OFFLINE')
                    ? 'OFFLINE'
                    : ($d->current_status ?: 'ONLINE');
                return [
                    'employee_id'    => $e->id,
                    'name'           => $e->fullName(),
                    'department'     => $e->department?->name,
                    'team'           => $e->team?->name,
                    'status'         => $status,
                    'last_seen'      => $d?->last_heartbeat_at,
                    'active_seconds' => (int) ($activeSecs[$e->id] ?? 0),
                    'idle_seconds'   => (int) ($idleSecs[$e->id] ?? 0),
                    'compliance_status' => $d?->compliance_status,
                ];
            });

        $byStatus = fn ($s) => $rows->where('status', $s)->count();
        $cards = [
            'total_employees'   => $rows->count(),
            'active_now'        => $byStatus('ONLINE'),
            'idle_now'          => $byStatus('IDLE'),
            'away_now'          => $byStatus('AWAY'),
            'offline'           => $byStatus('OFFLINE'),
            'on_break'          => $onBreak,
            'camera_blocked'    => EmployeePresenceEvent::whereBetween('started_at', $day)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->where('event_type', 'CAMERA_BLOCKED')->distinct()->count('employee_id'),
            'violations_today'  => EmployeeComplianceEvent::whereBetween('started_at', $day)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'screenshots_today' => EmployeeScreenshotLog::whereBetween('captured_at', $day)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
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
            'violations'        => EmployeeComplianceEvent::whereBetween('started_at', $day)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'screenshots'       => EmployeeScreenshotLog::whereBetween('captured_at', $day)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'active_hours_total' => round(((int) EmployeeActivityEvent::whereBetween('started_at', $day)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->where('event_type', 'ACTIVE')->sum('duration_seconds')) / 3600, 1),
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

        return response()->json(['data' => $devices]);
    }
}
