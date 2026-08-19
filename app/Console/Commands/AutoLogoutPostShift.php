<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use App\Services\PolicyResolver;
use App\Services\StatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Post-shift auto logout (Ejaz, 19-Aug-2026).
 *
 * The nightly `smartept:mark-attendance` already closes sessions left open across a day
 * boundary, but it runs at 00:15 and is retrospective — so between shift end and midnight
 * an employee who never signed out stays "logged in", and whatever stale instant happened
 * to sit in check_out_at is what the productivity report divides by. That is precisely the
 * shape of the 596% row (AI0043, 14-Aug: logout stamped 10:21 while activity ran to ~17:00).
 *
 * This command closes the loop in near-real time: every 5 minutes it finds still-open
 * sessions whose shift ended more than N minutes ago and signs them out AT
 * (shift end + N) — not at "now", so an employee whose PC sat idle overnight is not
 * credited the extra hours.
 *
 * N resolves per employee: shifts.post_shift_auto_logout_minutes, falling back to the
 * effective Attendance policy's post_shift_auto_logout_minutes. NULL in both = disabled,
 * so nothing changes for a tenant that has not configured it.
 *
 * What a close does, in order:
 *   1. employee_login_sessions  — logout_at, duration_seconds (16h cap), reason POST_SHIFT_AUTO
 *   2. employee_attendance_logs — check_out_at / final_logout_at advanced (never rewound)
 *   3. StatusService::closeAll  — end any open break / meeting segment so the live board clears
 *   4. employee_devices         — session_status FORCE_LOGOUT + the device's Sanctum token
 *                                 revoked, so the agent 401s on its next heartbeat and
 *                                 returns to the login screen instead of tracking all night
 */
class AutoLogoutPostShift extends Command
{
    protected $signature = 'smartept:auto-logout
        {--dry-run : List what would be closed, change nothing}
        {--now= : Treat this instant as "now" (Y-m-d H:i:s) — for testing}';

    protected $description = 'Sign out agents that never signed out, N minutes after their shift ends';

    /** Same cap the nightly job uses: an agent that died on Friday must not credit the weekend. */
    private const MAX_AUTO_SESSION_SECONDS = 16 * 3600;

    /** employee_id => resolved minutes|null, so the policy chain is walked once per employee. */
    private array $minutesCache = [];

    public function handle(PolicyResolver $resolver, StatusService $status): int
    {
        $now = $this->option('now') ? Carbon::parse($this->option('now')) : now();
        $dry = (bool) $this->option('dry-run');
        $closed = 0;
        $skipped = 0;

        EmployeeLoginSession::withoutGlobalScopes()
            ->with('employee.shift')
            ->whereNull('logout_at')
            ->whereNotNull('login_at')
            // Nothing older than 3 days: those belong to the nightly sweep, and reaching
            // further back would rewrite months of settled history on first deploy.
            ->where('login_at', '>=', $now->copy()->subDays(3))
            ->chunkById(200, function ($sessions) use ($resolver, $status, $now, $dry, &$closed, &$skipped) {
                foreach ($sessions as $session) {
                    $employee = $session->employee;
                    if (! $employee) {
                        $skipped++;
                        continue;
                    }

                    $minutes = $this->autoLogoutMinutes($employee, $resolver);
                    if ($minutes === null) {
                        $skipped++;
                        continue;   // not configured for this employee — leave the session alone
                    }

                    $cutoff = $this->cutoffFor($session, $employee, $minutes);
                    if (! $cutoff || $now->lessThan($cutoff)) {
                        continue;   // shift has not ended (plus the grace) yet
                    }

                    $this->line(sprintf(
                        '  %s (%s) — session #%d open since %s → sign out at %s (+%dm)',
                        $employee->employee_code, trim($employee->first_name . ' ' . $employee->last_name),
                        $session->id, $session->login_at->format('Y-m-d H:i'), $cutoff->format('Y-m-d H:i'), $minutes
                    ));

                    if (! $dry) {
                        $this->closeSession($session, $employee, $cutoff, $minutes, $status);
                    }
                    $closed++;
                }
            });

        $this->info(($dry ? '[dry-run] ' : '') . "Post-shift auto logout: {$closed} closed, {$skipped} not configured.");

        return self::SUCCESS;
    }

    /**
     * Effective minutes-after-shift-end for one employee. Per-shift wins; the Attendance
     * policy is the fallback default. null = the feature is off for this employee.
     */
    private function autoLogoutMinutes(Employee $employee, PolicyResolver $resolver): ?int
    {
        if (array_key_exists($employee->id, $this->minutesCache)) {
            return $this->minutesCache[$employee->id];
        }

        $minutes = $employee->shift?->post_shift_auto_logout_minutes;

        if ($minutes === null) {
            $policy = $resolver->resolvePolicy($employee, 'ATTENDANCE');
            $minutes = $policy['post_shift_auto_logout_minutes'] ?? null;
        }

        return $this->minutesCache[$employee->id] = $minutes === null ? null : max(0, (int) $minutes);
    }

    /**
     * The instant to stamp as the sign-out: the shift end that belongs to THIS session's
     * login, plus the configured minutes. Returns null when the employee has no shift times
     * (there is no "shift end" to measure from, so the nightly sweep keeps ownership).
     */
    private function cutoffFor(EmployeeLoginSession $session, Employee $employee, int $minutes): ?Carbon
    {
        $shift = $employee->shift;
        if (! $shift || ! $shift->start_time || ! $shift->end_time) {
            return null;
        }

        $loginDay = $session->login_at->toDateString();
        $end = Carbon::parse($loginDay . ' ' . $shift->end_time);
        $start = Carbon::parse($loginDay . ' ' . $shift->start_time);

        // Night shift: the shift's end belongs to the NEXT calendar day.
        if ($shift->crosses_midnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        // A login after that end (someone clocked in during the tail of a night shift, or
        // simply worked late) would give a cutoff already behind them — push it a day on so
        // the session gets its own shift-length window rather than being closed instantly.
        if ($end->lessThanOrEqualTo($session->login_at)) {
            $end->addDay();
        }

        return $end->addMinutes($minutes);
    }

    /** Close the session, advance the attendance sheet, clear live status, and kick the agent. */
    private function closeSession(EmployeeLoginSession $session, Employee $employee, Carbon $cutoff, int $minutes, StatusService $status): void
    {
        $session->update([
            'logout_at'        => $cutoff,
            'duration_seconds' => min(
                (int) $cutoff->diffInSeconds($session->login_at, true),
                self::MAX_AUTO_SESSION_SECONDS
            ),
            'logout_reason'    => 'POST_SHIFT_AUTO',
        ]);

        // Attendance sheet: only ever move check-out FORWARD. A real punch-out or a manual
        // HR correction that is already later than the cutoff must win.
        $attendance = EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $session->login_at->toDateString())
            ->first();

        if ($attendance) {
            $updates = [];
            if (! $attendance->check_out_at || $attendance->check_out_at->lessThan($cutoff)) {
                $updates['check_out_at'] = $cutoff;
                $updates['check_out_source'] = 'AUTO_POST_SHIFT';
            }
            if (! $attendance->final_logout_at || $attendance->final_logout_at->lessThan($cutoff)) {
                $updates['final_logout_at'] = $cutoff;
            }
            // Signed out AT shift end + N, so by definition this is not an early logout.
            $updates['early_logout_minutes'] = 0;
            $updates['derivation_note'] = trim((string) $attendance->derivation_note . ' | auto sign-out '
                . $cutoff->format('Y-m-d H:i') . ' (shift end + ' . $minutes . 'm)', ' |');
            $attendance->update($updates);
        }

        // End any break / meeting segment left open, so the live board stops showing a ghost.
        try {
            $status->closeAll($employee, $cutoff);
        } catch (\Throwable $e) {
            $this->warn("  status close failed for {$employee->employee_code}: {$e->getMessage()}");
        }

        // Kick the agent: revoke this device's token so it 401s on the next heartbeat and
        // drops back to the login screen instead of tracking through the night.
        $devices = EmployeeDevice::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('session_status', 'ACTIVE')
            ->get();

        foreach ($devices as $device) {
            $employee->user?->tokens()->where('name', 'device:' . $device->device_uuid)->delete();
            $device->update([
                'session_status'  => 'FORCE_LOGOUT',
                'force_logout_at' => now(),
                'current_status'  => 'OFFLINE',
            ]);
        }
    }
}
