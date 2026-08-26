<?php

namespace App\Console\Commands;

use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeLoginSession;
use App\Models\PolicyAssignment;
use App\Services\WorkCalendar;
use App\Support\ResolvesLocalNow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly attendance completion (runs 00:15, before smartept:daily-summary so
 * scores are built on a finished sheet):
 *
 *  1. Auto-close sessions the agent never closed (crash / power cut / forgotten
 *     machine) at the login day's shift end, capped at 16h.
 *  2. Mark ACTIVE employees with no attendance row ABSENT — working days only,
 *     so weekly offs and holidays never produce ABSENT rows.
 *  3. Downgrade PRESENT to HALF_DAY when the day's worked time is below half the
 *     expected day.
 */
class MarkAttendance extends Command
{
    use ResolvesLocalNow;

    protected $signature = 'smartept:mark-attendance {--date= : YYYY-MM-DD (defaults to yesterday)}';
    protected $description = 'Close stale sessions and complete the attendance sheet (ABSENT / HALF_DAY) for a day';

    /** Cap for auto-closed sessions: an agent that died on Friday must not credit a whole weekend. */
    private const MAX_AUTO_SESSION_SECONDS = 16 * 3600;

    /** company_id => min working seconds from the company AttendancePolicy (null = none assigned). */
    private array $minSecondsCache = [];

    public function handle(WorkCalendar $calendar, \App\Services\AttendanceDerivation $derivation): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();
        $this->info("Completing attendance for {$date}...");

        $closed = $this->closeStaleSessions($date);

        $absent = 0;
        $halfDay = 0;
        Employee::withoutGlobalScopes()->where('employment_status', 'ACTIVE')
            ->chunkById(200, function ($employees) use ($calendar, $derivation, $date, &$absent, &$halfDay) {
                foreach ($employees as $employee) {
                    // Weekly offs / holidays are never marked — no ABSENT (or HALF_DAY)
                    // rows for days the employee was not expected to work.
                    if (! $calendar->isWorkingDay($employee, $date)) {
                        continue;
                    }

                    $rows = EmployeeAttendanceLog::withoutGlobalScopes()
                        ->where('employee_id', $employee->id)
                        ->whereDate('work_date', $date)
                        ->get();

                    if ($rows->isEmpty()) {
                        EmployeeAttendanceLog::create([
                            'company_id'  => $employee->company_id,
                            'employee_id' => $employee->id,
                            'work_date'   => $date,
                            'source'      => 'MANUAL',
                            'status'      => 'ABSENT',
                            'notes'       => 'Auto-marked absent',
                        ]);
                        $absent++;
                        continue;
                    }

                    // QA Phase 3 (B7): finalise the shift-aware checkout now the day is
                    // fully closed — a trailing door OUT with no agent LOGOUT is the real
                    // checkout. Never throws into the nightly run; never touches MANUAL.
                    try {
                        $derivation->deriveDay($employee, $date);
                        $rows = EmployeeAttendanceLog::withoutGlobalScopes()
                            ->where('employee_id', $employee->id)
                            ->whereDate('work_date', $date)
                            ->get();
                    } catch (\Throwable $e) {
                        // leave the raw rows in place if derivation fails
                    }

                    $halfDay += $this->applyHalfDayRule($employee, $date, $rows);
                }
            });

        $this->info("Done. Sessions auto-closed: {$closed}, marked ABSENT: {$absent}, marked HALF_DAY: {$halfDay}.");
        return self::SUCCESS;
    }

    /**
     * Close every still-open session whose shift has ended, at the login day's shift end
     * (or 23:59:59 without a shift), and finish the close properly: stamp the attendance
     * sheet and stop the device reading ONLINE.
     *
     * 26-Aug-2026 (Ejaz) — two defects fixed here:
     *
     *  1. The filter was `login_at < $date`, i.e. strictly BEFORE the day being processed.
     *     This job runs at 00:15 to complete YESTERDAY, so yesterday's own forgotten
     *     sessions were skipped and only closed the following night — a ~24-hour window in
     *     which the employee kept showing as signed in and the agent kept logging idle
     *     time. It now considers the processed day too, but closes a session only once its
     *     shift end is actually in the past, so a night shift still running at 00:15 is
     *     left alone (which is what the old `<` was really protecting).
     *  2. Closing the login session alone left `check_out_at` empty and the device row
     *     still ONLINE / ACTIVE. The productivity report reads the attendance sheet, and
     *     the Devices screen reads the device row, so the "still online today" symptom
     *     survived the close. Both are now stamped, forward-only.
     */
    private function closeStaleSessions(string $date): int
    {
        $closed = 0;

        EmployeeLoginSession::withoutGlobalScopes()->with('employee.shift')
            ->whereNull('logout_at')
            ->whereDate('login_at', '<=', $date)
            ->chunkById(200, function ($sessions) use (&$closed) {
                foreach ($sessions as $session) {
                    $loginDay = $session->login_at->toDateString();
                    $shift = $session->employee?->shift;
                    $logoutAt = Carbon::parse($loginDay . ' ' . ($shift?->end_time ?: '23:59:59'));

                    // Night shift: the end belongs to the next calendar day.
                    if ($shift && ($shift->crosses_midnight
                        || ($shift->start_time && $logoutAt->lessThanOrEqualTo(Carbon::parse($loginDay . ' ' . $shift->start_time))))) {
                        $logoutAt->addDay();
                    }

                    // A login AFTER the shift end (late-night work) would yield a negative
                    // session; fall back to end of the login day.
                    if ($logoutAt->lessThanOrEqualTo($session->login_at)) {
                        $logoutAt = Carbon::parse($loginDay . ' 23:59:59');
                    }

                    // Still inside the shift — a night shift in progress, not a stale session.
                    // Compared on the EMPLOYEE's wall clock: sessions are stored as local times,
                    // so a UTC now() is hours out and mis-decides this (26-Aug-2026).
                    if ($logoutAt->greaterThan($this->localNow($session->company_id))) {
                        continue;
                    }

                    $session->update([
                        'logout_at'        => $logoutAt,
                        'duration_seconds' => min(
                            (int) $logoutAt->diffInSeconds($session->login_at, true),
                            self::MAX_AUTO_SESSION_SECONDS
                        ),
                        'logout_reason'    => 'AUTO_CLOSED',
                    ]);

                    $this->finishClose($session, $logoutAt);
                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * Carry an auto-close through to the two places that are actually read by users: the
     * attendance sheet (which the productivity report divides by) and the device row (which
     * the Devices screen prints). Both move FORWARD only — a real punch-out or an HR
     * correction that is already later than the cutoff must win.
     */
    private function finishClose(EmployeeLoginSession $session, Carbon $logoutAt): void
    {
        $attendance = EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('employee_id', $session->employee_id)
            ->whereDate('work_date', $session->login_at->toDateString())
            ->first();

        if ($attendance) {
            $updates = [];
            if (! $attendance->check_out_at || $attendance->check_out_at->lessThan($logoutAt)) {
                $updates['check_out_at'] = $logoutAt;
                $updates['check_out_source'] = 'AUTO_CLOSED';
            }
            if (! $attendance->final_logout_at || $attendance->final_logout_at->lessThan($logoutAt)) {
                $updates['final_logout_at'] = $logoutAt;
            }
            if ($updates) {
                $updates['derivation_note'] = trim((string) $attendance->derivation_note
                    . ' | session auto-closed ' . $logoutAt->format('Y-m-d H:i') . ' (no sign-out recorded)', ' |');
                $attendance->update($updates);
            }
        }

        // Revoke the token as well as flipping the row. Flipping alone (26-Aug-2026) marked
        // the employee signed out on every screen while the agent kept a live credential and
        // went on uploading violations — the console read 0 active agents with the violations
        // tab still filling. The agent stops when its token dies.
        foreach (\App\Models\EmployeeDevice::withoutGlobalScopes()
            ->where('employee_id', $session->employee_id)
            ->where('session_status', 'ACTIVE')->get() as $device) {
            $device->revokeAgentToken();
            $device->update(['session_status' => 'LOGGED_OUT', 'current_status' => 'OFFLINE']);
        }
    }

    /**
     * PRESENT → HALF_DAY when worked time is below half of the expected day.
     * Returns 1 when the employee was downgraded, 0 otherwise.
     */
    private function applyHalfDayRule(Employee $employee, string $date, $rows): int
    {
        $present = $rows->where('status', 'PRESENT');
        if ($present->isEmpty()) {
            return 0; // Only PRESENT is ever downgraded — never touch ON_LEAVE/ABSENT/manual fixes.
        }

        $seconds = (int) EmployeeLoginSession::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('login_at', $date)
            ->sum('duration_seconds');

        // Biometric-only days have no agent sessions; use the punch span instead so a
        // full day at the gate is not misread as zero time worked.
        if ($seconds === 0) {
            $row = $present->first();
            if ($row->check_in_at && $row->check_out_at) {
                $seconds = (int) $row->check_out_at->diffInSeconds($row->check_in_at, true);
            }
        }

        if ($seconds >= $this->halfDayThresholdSeconds($employee)) {
            return 0;
        }

        foreach ($present as $row) {
            $row->update([
                'status' => 'HALF_DAY',
                'notes'  => trim(($row->notes ? $row->notes . "\n" : '') . 'Auto-marked half day (worked below half-day threshold)'),
            ]);
        }

        return 1;
    }

    /**
     * Half-day cut-off = half the expected full day. The company AttendancePolicy's
     * min_working_hours (when one is assigned) overrides the shift span; employees
     * without a shift default to 8h — mirrors ScoringService::expectedSeconds().
     */
    private function halfDayThresholdSeconds(Employee $employee): int
    {
        $full = $this->companyMinWorkingSeconds($employee->company_id) ?? $this->expectedSeconds($employee);

        return (int) floor($full / 2);
    }

    private function companyMinWorkingSeconds(int $companyId): ?int
    {
        if (! array_key_exists($companyId, $this->minSecondsCache)) {
            $assignment = PolicyAssignment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('policy_type', 'ATTENDANCE')
                ->where('assignable_type', 'COMPANY')
                ->where('assignable_id', $companyId)
                ->first();

            $policy = $assignment ? AttendancePolicy::withoutGlobalScopes()->find($assignment->policy_id) : null;

            $this->minSecondsCache[$companyId] = ($policy && (int) $policy->min_working_hours > 0)
                ? (int) $policy->min_working_hours * 3600
                : null;
        }

        return $this->minSecondsCache[$companyId];
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
            $end->addDay(); // crosses midnight
        }

        return max(3600, (int) $end->diffInSeconds($start, true));
    }
}
