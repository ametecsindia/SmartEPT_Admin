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
        $meeting = (int) \App\Models\EmployeeMeetingSession::where('employee_id', $employee->id)
            ->whereDate('actual_start_at', $date)->sum('duration_seconds');
        $allotted = self::allottedBreakSeconds($employee->shift, $present);
        $working = self::netWorkingSeconds($present, $allotted);

        // Productivity (QA rule): productive working time (active + meeting) as a share of the
        // ACTUAL working window = present time minus the allotted break (pro-rated on an early
        // logout). Idle/violations are scored separately (compliance).
        $productivity = $this->productivityScore($active, $meeting, $working);
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

    /** Productivity % (QA rule): productive working time (active + meeting) / net working window. */
    private function productivityScore(int $active, int $meeting, int $working): float
    {
        $working = max(1, $working);

        return round(max(0, min(100, (($active + $meeting) / $working) * 100)), 2);
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

    /** Scheduled shift length in seconds (default 8h when no shift is set). */
    public static function shiftExpectedSeconds($shift): int
    {
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

    /** Allotted break for the day: the shift's break_minutes_allowed (fallback: 1 hour per 9-hour
     *  shift), PRO-RATED to how much of the scheduled shift the employee was actually present. */
    public static function allottedBreakSeconds($shift, int $present): int
    {
        $expected = self::shiftExpectedSeconds($shift);
        $full = ($shift && $shift->break_minutes_allowed !== null)
            ? (int) $shift->break_minutes_allowed * 60
            : (int) round($expected / 9);
        $fraction = $expected > 0 ? min(1.0, max(0.0, $present / $expected)) : 1.0;

        return (int) round($full * $fraction);
    }

    /** Net working window used for productivity + break adherence = present − allotted break. */
    public static function netWorkingSeconds(int $present, int $allotted): int
    {
        return max(1, $present - $allotted);
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
