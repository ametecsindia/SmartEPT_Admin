<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAppUsageLog;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDailySummary;
use App\Models\EmployeeLoginSession;
use App\Models\EmployeePresenceEvent;
use App\Models\EmployeeScreenshotLog;
use Illuminate\Support\Carbon;

/**
 * Computes the productivity and compliance scores (0–100) for an employee/day and writes
 * the daily summary. Server-side so scores cannot be manipulated on the endpoint.
 *
 * Productivity = active-time base − idle/away/non-productive/late/early penalties.
 * Compliance   = 100 − penalties per violation class.
 */
class ScoringService
{
    public function buildSummary(Employee $employee, string $date): EmployeeDailySummary
    {
        $active = $this->sum(EmployeeActivityEvent::class, $employee->id, 'started_at', $date, ['event_type' => 'ACTIVE']);
        $idle   = max(
            $this->sum(EmployeeActivityEvent::class, $employee->id, 'started_at', $date, ['event_type' => 'IDLE']),
            $this->sumRaw('employee_idle_logs', $employee->id, 'idle_start', $date)
        );
        $break  = $this->sumRaw('employee_break_logs', $employee->id, 'start_at', $date);

        $productive = (int) EmployeeAppUsageLog::where('employee_id', $employee->id)
            ->whereDate('start_at', $date)
            ->whereIn('category', ['PRODUCTIVE', 'CLIENT_REQUIRED', 'COMMUNICATION'])
            ->sum('duration_seconds');
        $nonProductive = (int) EmployeeAppUsageLog::where('employee_id', $employee->id)
            ->whereDate('start_at', $date)
            ->whereIn('category', ['NON_PRODUCTIVE', 'BLOCKED', 'RESTRICTED'])
            ->sum('duration_seconds');

        $away = (int) EmployeePresenceEvent::where('employee_id', $employee->id)
            ->whereDate('started_at', $date)->where('event_type', 'AWAY_FROM_SCREEN')->sum('duration_seconds');

        $violations = EmployeeComplianceEvent::where('employee_id', $employee->id)->whereDate('started_at', $date)->count();
        $shots = EmployeeScreenshotLog::where('employee_id', $employee->id)->whereDate('captured_at', $date)->count();

        $firstLogin = EmployeeLoginSession::where('employee_id', $employee->id)->whereDate('login_at', $date)->min('login_at');
        $lastLogout = EmployeeLoginSession::where('employee_id', $employee->id)->whereDate('login_at', $date)->max('logout_at');

        $attendance = EmployeeAttendanceLog::where('employee_id', $employee->id)->whereDate('work_date', $date)->first();
        $late = (int) ($attendance->late_minutes ?? 0);
        $early = (int) ($attendance->early_logout_minutes ?? 0);

        $present = $active + $idle;
        $expected = $this->expectedSeconds($employee);

        $productivity = $this->productivityScore($active, $productive, $idle, $away, $nonProductive, $late, $early, $expected);
        $compliance = $this->complianceScore($employee, $date);

        // whereDate: work_date is a date-cast column; a plain where() misses on
        // sqlite ('Y-m-d' vs stored 'Y-m-d 00:00:00') and re-runs then violate the
        // unique index. This lookup is portable across MySQL and sqlite.
        $summary = EmployeeDailySummary::withoutGlobalScopes()
            ->where('employee_id', $employee->id)->whereDate('work_date', $date)->first()
            ?? new EmployeeDailySummary(['employee_id' => $employee->id, 'work_date' => $date]);

        $summary->fill([
                'company_id'             => $employee->company_id,
                'present_seconds'        => $present,
                'active_seconds'         => $active,
                'idle_seconds'           => $idle,
                'away_seconds'           => $away,
                'break_seconds'          => $break,
                'productive_app_seconds' => $productive,
                'non_productive_seconds' => $nonProductive,
                'first_login_at'         => $firstLogin,
                'last_logout_at'         => $lastLogout,
                'late_minutes'           => $late,
                'early_logout_minutes'   => $early,
                'violation_count'        => $violations,
                'screenshot_count'       => $shots,
                'productivity_score'     => $productivity,
                'compliance_score'       => $compliance,
            ])->save();

        return $summary;
    }

    private function productivityScore(int $active, int $productive, int $idle, int $away, int $nonProd, int $late, int $early, int $expected): float
    {
        $expected = max(1, $expected);
        // Base: productive active time as a share of expected working time (active counts, productive counts double-weighted).
        $base = min(100, (($active + $productive) / (2 * $expected)) * 100);
        $base -= min(25, ($idle / $expected) * 25);
        $base -= min(15, ($away / $expected) * 15);
        $base -= min(20, ($nonProd / $expected) * 20);
        $base -= min(10, $late / 6);
        $base -= min(10, $early / 6);

        return round(max(0, min(100, $base)), 2);
    }

    private function complianceScore(Employee $employee, string $date): float
    {
        $events = EmployeeComplianceEvent::where('employee_id', $employee->id)->whereDate('started_at', $date)->get();
        $weights = [
            'BLOCKED_APP_OPENED' => 5, 'BLOCKED_WEBSITE_OPENED' => 5, 'UNAUTHORIZED_NETWORK' => 8,
            'USB_VIOLATION' => 6, 'AGENT_TAMPER' => 15, 'CAMERA_BLOCKED' => 3,
            'SCREENSHOT_DISABLED' => 8, 'VPN_PROXY_DETECTED' => 6,
        ];
        $sevMult = ['LOW' => 0.5, 'MEDIUM' => 1, 'HIGH' => 1.5, 'CRITICAL' => 2];

        $penalty = 0;
        foreach ($events as $e) {
            $w = $weights[$e->event_type] ?? 3;
            $penalty += $w * ($sevMult[$e->severity] ?? 1);
        }

        return round(max(0, min(100, 100 - $penalty)), 2);
    }

    private function expectedSeconds(Employee $employee): int
    {
        $shift = $employee->shift;
        if (! $shift || ! $shift->start_time || ! $shift->end_time) {
            return 8 * 3600;
        }
        $start = Carbon::parse($shift->start_time);
        $end = Carbon::parse($shift->end_time);
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }
        return max(3600, (int) $end->diffInSeconds($start, true));
    }

    private function sum(string $model, int $employeeId, string $col, string $date, array $where = []): int
    {
        return (int) $model::where('employee_id', $employeeId)->whereDate($col, $date)->where($where)->sum('duration_seconds');
    }

    private function sumRaw(string $table, int $employeeId, string $col, string $date): int
    {
        return (int) \DB::table($table)->where('employee_id', $employeeId)->whereDate($col, $date)->sum('duration_seconds');
    }
}
