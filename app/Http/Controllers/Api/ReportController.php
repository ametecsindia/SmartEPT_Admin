<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeIdleLog;
use App\Models\EmployeeLoginSession;
use App\Models\EmployeePresenceEvent;
use App\Models\EmployeeScreenshotLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * GET /api/reports/employee/{employee}/timeline?date=
     * Merges attendance, breaks, idle, presence, screenshots and compliance into one
     * chronological, human-readable feed.
     */
    public function timeline(Request $request, Employee $employee): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());
        $entries = [];
        $add = function ($time, $type, $label, $detail = null) use (&$entries) {
            if ($time) {
                $entries[] = ['time' => (string) $time, 'type' => $type, 'label' => $label, 'detail' => $detail];
            }
        };

        foreach (EmployeeLoginSession::where('employee_id', $employee->id)->whereDate('login_at', $date)->get() as $s) {
            $add($s->login_at, 'LOGIN', 'Logged in');
            $add($s->logout_at, 'LOGOUT', 'Logged out', $s->logout_reason);
        }
        foreach (EmployeeBreakLog::where('employee_id', $employee->id)->whereDate('start_at', $date)->get() as $b) {
            $add($b->start_at, 'BREAK_START', $b->break_type . ' break started');
            $add($b->end_at, 'BREAK_END', $b->break_type . ' break ended');
        }
        foreach (EmployeeIdleLog::where('employee_id', $employee->id)->whereDate('idle_start', $date)->where('duration_seconds', '>=', 300)->get() as $i) {
            $add($i->idle_start, 'IDLE', 'Idle started', $i->reason);
            $add($i->idle_end, 'IDLE_END', 'Idle ended');
        }
        foreach (EmployeePresenceEvent::where('employee_id', $employee->id)->whereDate('started_at', $date)->whereIn('event_type', ['AWAY_FROM_SCREEN', 'CAMERA_BLOCKED', 'MULTIPLE_FACE_DETECTED'])->get() as $p) {
            $add($p->started_at, 'PRESENCE', str_replace('_', ' ', $p->event_type));
        }
        foreach (EmployeeScreenshotLog::where('employee_id', $employee->id)->whereDate('captured_at', $date)->get() as $sh) {
            $add($sh->captured_at, 'SCREENSHOT', 'Screenshot captured', $sh->trigger_reason);
        }
        foreach (EmployeeComplianceEvent::where('employee_id', $employee->id)->whereDate('started_at', $date)->get() as $c) {
            $add($c->started_at, 'VIOLATION', str_replace('_', ' ', $c->event_type), $c->detected_value);
        }

        usort($entries, fn ($a, $b) => strcmp($a['time'], $b['time']));

        return response()->json(['employee' => ['id' => $employee->id, 'name' => $employee->fullName()], 'date' => $date, 'timeline' => $entries]);
    }
}
