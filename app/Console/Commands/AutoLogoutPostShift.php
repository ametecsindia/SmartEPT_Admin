<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use App\Services\PolicyResolver;
use App\Support\ResolvesLocalNow;
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
    use ResolvesLocalNow;

    protected $signature = 'smartept:auto-logout
        {--dry-run : List what would be closed, change nothing}
        {--explain : Print EVERY open session with the reason it was or was not signed out}
        {--now= : Treat this instant as "now" (Y-m-d H:i:s) — for testing}';

    protected $description = 'Sign out agents that never signed out, N minutes after their shift ends';

    /** Same cap the nightly job uses: an agent that died on Friday must not credit the weekend. */
    private const MAX_AUTO_SESSION_SECONDS = 16 * 3600;

    /** employee_id => resolved minutes|null, so the policy chain is walked once per employee. */
    private array $minutesCache = [];

    public function handle(PolicyResolver $resolver, StatusService $status): int
    {
        // Wall-clock now, not UTC now — the agent stores local times. `--now=` is already a
        // local clock face. See ResolvesLocalNow; the per-company value is resolved below,
        // this one only bounds the "last 3 days" query.
        $now = $this->option('now') ? Carbon::parse($this->option('now')) : $this->localNow(null);
        $dry = (bool) $this->option('dry-run');
        $explain = (bool) $this->option('explain');
        $closed = 0;
        $open = 0;
        // 26-Aug-2026: the old counters said only "N not configured", which is the same
        // number whether the feature is switched off, the employee has no shift, or the
        // attendance policy was never ASSIGNED to anyone. Those need different fixes, so
        // the reasons are counted separately and --explain prints them per session.
        $reasons = [];

        EmployeeLoginSession::withoutGlobalScopes()
            ->with('employee.shift')
            ->whereNull('logout_at')
            ->whereNotNull('login_at')
            // Nothing older than 3 days: those belong to the nightly sweep, and reaching
            // further back would rewrite months of settled history on first deploy.
            ->where('login_at', '>=', $now->copy()->subDays(3))
            ->chunkById(200, function ($sessions) use ($resolver, $status, $now, $dry, $explain, &$closed, &$open, &$reasons) {
                foreach ($sessions as $session) {
                    $open++;
                    $employee = $session->employee;
                    $who = $employee
                        ? $employee->employee_code . ' (' . trim($employee->first_name . ' ' . $employee->last_name) . ')'
                        : 'session #' . $session->id;
                    $since = $session->login_at->format('Y-m-d H:i');

                    $note = function (string $reason) use (&$reasons, $explain, $who, $since) {
                        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                        if ($explain) {
                            $this->line(sprintf('  <fg=yellow>skip</> %-34s open since %s — %s', $who, $since, $reason));
                        }
                    };

                    if (! $employee) {
                        $note('session has no employee record');
                        continue;
                    }

                    $minutes = $this->autoLogoutMinutes($employee, $resolver);
                    if ($minutes === null) {
                        $note($employee->shift_id
                            ? 'auto sign-out minutes not set on the shift, and no Attendance policy with a value is assigned'
                            : 'employee has no shift assigned, and no Attendance policy with a value is assigned');
                        continue;
                    }

                    $cutoff = $this->cutoffFor($session, $employee, $minutes);
                    if (! $cutoff) {
                        $note('no shift end and no login day to measure from');
                        continue;
                    }
                    // The employee's OWN wall clock decides whether their shift has ended.
                    $empNow = $this->option('now') ? $now : $this->localNow($employee->company_id);
                    if ($empNow->lessThan($cutoff)) {
                        $note('shift ends ' . $cutoff->format('Y-m-d H:i') . ' — not due yet');
                        continue;   // shift has not ended (plus the grace) yet
                    }

                    $this->line(sprintf(
                        '  <fg=green>close</> %-34s open since %s → sign out at %s (+%dm)',
                        $who, $since, $cutoff->format('Y-m-d H:i'), $minutes
                    ));

                    if (! $dry) {
                        $this->closeSession($session, $employee, $cutoff, $minutes, $status);
                    }
                    $closed++;
                }
            });

        $this->info(($dry ? '[dry-run] ' : '') . "Post-shift auto logout: {$closed} of {$open} open sessions signed out.");

        foreach ($reasons as $reason => $count) {
            $this->line("  <fg=yellow>{$count}</> left open — {$reason}");
        }
        if ($reasons && ! $explain) {
            $this->line('  Run with --explain to see which employees.');
        }

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
        $loginDay = $session->login_at->toDateString();
        $shift = $employee->shift;

        // 26-Aug-2026: an employee with no shift, or a shift saved without times, used to
        // return null here and could therefore NEVER be signed out by this command — while
        // the nightly sweep would not reach them until the following night either, so their
        // agent tracked idle time straight through. Fall back to the end of the login day,
        // which is exactly the boundary `smartept:mark-attendance` already uses for them.
        if (! $shift || ! $shift->end_time) {
            return Carbon::parse($loginDay . ' 23:59:59')->addMinutes($minutes);
        }

        $end = Carbon::parse($loginDay . ' ' . $shift->end_time);

        // Night shift: the shift's end belongs to the NEXT calendar day.
        if ($shift->crosses_midnight
            || ($shift->start_time && $end->lessThanOrEqualTo(Carbon::parse($loginDay . ' ' . $shift->start_time)))) {
            $end->addDay();
        }

        // A login AFTER the shift end is NOT given a fresh window (Ejaz, 26-Aug-2026:
        // "irrespective of the re-login ... it should consider the post shift logout time and
        // sign out the agent"). This used to `addDay()`, which handed such a session the whole
        // of the next day. The cutoff stays at THIS login day's shift end + N, so signing back
        // in after the shift is over is closed on the very next pass. A genuine night shift is
        // already moved forward by the crosses_midnight branch above and is unaffected.

        return $end->addMinutes($minutes);
    }

    /** Close the session, advance the attendance sheet, clear live status, and kick the agent. */
    private function closeSession(EmployeeLoginSession $session, Employee $employee, Carbon $cutoff, int $minutes, StatusService $status): void
    {
        // A session that STARTED after the shift end has a cutoff behind its own login. Stamp
        // the login instant instead of a logout that precedes it (which would read as a
        // negative session and, through diffInSeconds' absolute value, a bogus duration).
        $logoutAt = $cutoff->lessThan($session->login_at) ? $session->login_at->copy() : $cutoff;

        $session->update([
            'logout_at'        => $logoutAt,
            'duration_seconds' => min(
                (int) $logoutAt->diffInSeconds($session->login_at, true),
                self::MAX_AUTO_SESSION_SECONDS
            ),
            'logout_reason'    => 'POST_SHIFT_AUTO',
        ]);
        $cutoff = $logoutAt;

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
        // NOT filtered to session_status = ACTIVE. A device whose row had already moved to
        // LOGGED_OUT / FORCE_LOGOUT was skipped entirely, so its token was never revoked and
        // the agent carried on tracking against a closed session (26-Aug-2026). Revoking a
        // token that is already gone costs one query and is always safe.
        $devices = EmployeeDevice::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNull('unbound_at')
            ->get();

        foreach ($devices as $device) {
            $device->revokeAgentToken();
            $device->update([
                'session_status'  => 'FORCE_LOGOUT',
                'force_logout_at' => $this->localNow($employee->company_id),
                'current_status'  => 'OFFLINE',
            ]);
        }
    }
}
