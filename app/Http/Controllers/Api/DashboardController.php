<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    /** GET /api/dashboard/live-status — cards + employee live table (tenant-scoped). */
    public function liveStatus(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $onlineWindow = now()->subMinutes(3);

        $devices = EmployeeDevice::query()->get();
        $byStatus = fn ($s) => $devices->where('current_status', $s)->count();

        // A device is "offline" if its heartbeat is stale, regardless of last reported status.
        $stale = $devices->filter(fn ($d) => ! $d->last_heartbeat_at || $d->last_heartbeat_at->lt($onlineWindow))->count();

        $activeSecs = EmployeeActivityEvent::query()
            ->whereDate('started_at', $today)->where('event_type', 'ACTIVE')
            ->select('employee_id', DB::raw('SUM(duration_seconds) as s'))
            ->groupBy('employee_id')->pluck('s', 'employee_id');
        $idleSecs = EmployeeActivityEvent::query()
            ->whereDate('started_at', $today)->where('event_type', 'IDLE')
            ->select('employee_id', DB::raw('SUM(duration_seconds) as s'))
            ->groupBy('employee_id')->pluck('s', 'employee_id');

        $onBreak = EmployeeBreakLog::whereNull('end_at')->whereDate('start_at', $today)
            ->distinct()->count('employee_id');

        $cards = [
            'total_employees'   => Employee::where('employment_status', 'ACTIVE')->count(),
            'active_now'        => $devices->where('current_status', 'ONLINE')->filter(fn ($d) => $d->last_heartbeat_at && $d->last_heartbeat_at->gte($onlineWindow))->count(),
            'idle_now'          => $byStatus('IDLE'),
            'away_now'          => $byStatus('AWAY'),
            'offline'           => $stale,
            'on_break'          => $onBreak,
            'camera_blocked'    => EmployeePresenceEvent::whereDate('started_at', $today)->where('event_type', 'CAMERA_BLOCKED')->distinct()->count('employee_id'),
            'violations_today'  => EmployeeComplianceEvent::whereDate('started_at', $today)->count(),
            'screenshots_today' => EmployeeScreenshotLog::whereDate('captured_at', $today)->count(),
        ];

        $devByEmp = $devices->keyBy('employee_id');
        $rows = Employee::with(['team:id,name', 'department:id,name'])
            ->where('employment_status', 'ACTIVE')
            ->get()
            ->map(function ($e) use ($devByEmp, $activeSecs, $idleSecs, $onlineWindow) {
                $d = $devByEmp->get($e->id);
                $online = $d && $d->last_heartbeat_at && $d->last_heartbeat_at->gte($onlineWindow);
                return [
                    'employee_id'    => $e->id,
                    'name'           => $e->fullName(),
                    'department'     => $e->department?->name,
                    'team'           => $e->team?->name,
                    'status'         => $online ? ($d->current_status ?? 'ONLINE') : 'OFFLINE',
                    'last_seen'      => $d?->last_heartbeat_at,
                    'active_seconds' => (int) ($activeSecs[$e->id] ?? 0),
                    'idle_seconds'   => (int) ($idleSecs[$e->id] ?? 0),
                    'compliance_status' => $d?->compliance_status,
                ];
            });

        return response()->json(['cards' => $cards, 'employees' => $rows]);
    }

    /** GET /api/dashboard/summary — headline counts for a date. */
    public function summary(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        return response()->json([
            'date'              => $date,
            'employees'         => Employee::where('employment_status', 'ACTIVE')->count(),
            'devices'           => EmployeeDevice::count(),
            'violations'        => EmployeeComplianceEvent::whereDate('started_at', $date)->count(),
            'screenshots'       => EmployeeScreenshotLog::whereDate('captured_at', $date)->count(),
            'active_hours_total' => round(((int) EmployeeActivityEvent::whereDate('started_at', $date)->where('event_type', 'ACTIVE')->sum('duration_seconds')) / 3600, 1),
        ]);
    }

    /** GET /api/dashboard/device-health — agent + device health overview. */
    public function deviceHealth(Request $request): JsonResponse
    {
        $devices = EmployeeDevice::with('employee:id,first_name,last_name,employee_code')
            ->orderByDesc('last_heartbeat_at')
            ->get(['id', 'employee_id', 'device_uuid', 'computer_name', 'os_version', 'app_version', 'agent_health', 'compliance_status', 'current_status', 'sync_pending_count', 'last_heartbeat_at', 'last_sync_at']);

        return response()->json(['data' => $devices]);
    }
}
