<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeLoginSession;
use App\Services\PolicyResolver;
use App\Support\ResolvesLocalNow;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * "The shift end and the auto sign-out minutes are both set — so why is the employee
 *  still signed in?" (Ejaz, 2-Sep-2026, third report of the same symptom.)
 *
 * Auto sign-out is a chain of SIX links and it fails silently at every one of them. The
 * four things a user sees — Live Dashboard says Available, the attendance sheet has no
 * sign-out time, the agent is still signed in on the PC, and the productivity report shows
 * a day running past the shift — are ONE fault with four faces, never four faults.
 *
 * The links, in the order they break:
 *
 *   1. `php artisan schedule:run` is not running at all (no cron / no Windows Task
 *      Scheduler job). Nothing in routes/console.php ever fires. This is the usual answer
 *      and it is invisible: the setting saves, the screen looks right, and the job that
 *      would act on it does not exist.
 *   2. The scheduler runs, but auto-logout's withoutOverlapping mutex is stuck — a run
 *      killed mid-flight (deploy, restart, PHP timeout) leaves the lock behind and Laravel
 *      holds it for 24 hours by default, during which the command is skipped in silence.
 *   3. The migration that adds post_shift_auto_logout_minutes was never run, so the value
 *      the admin typed has nowhere to live.
 *   4. The minutes resolve to null for this employee — not set on the shift, and no
 *      Attendance policy carrying a value is ASSIGNED to them (a policy that exists but was
 *      never assigned resolves to null and the feature stays off).
 *   5. The wall clock disagrees: shifts store a local clock face, and a company left on the
 *      app default of UTC compares 18:30 IST against 13:00 and reports "not due yet" for
 *      another five and a half hours.
 *   6. Everything above is fine and the session genuinely is not due yet.
 *
 * Read-only. It changes nothing and is safe to run on a client's live server.
 */
class WhyNoSignOut extends Command
{
    use ResolvesLocalNow;

    protected $signature = 'smartept:why-no-signout
        {--all : Include sessions that are simply not due yet}';

    protected $description = 'Explain, per open session, why post-shift auto sign-out has not signed it out';

    /** Mirrors AutoLogoutPostShift: sessions older than this belong to the nightly sweep. */
    private const AUTO_LOGOUT_LOOKBACK_DAYS = 3;

    public function handle(PolicyResolver $resolver, Schedule $schedule): int
    {
        $this->line('');
        $this->line('  <options=bold>SmartEPT — why has the agent not been signed out?</>');
        $this->line('');

        $blocked = false;

        // ── Link 1: is the scheduler running at all? ───────────────────────────────────
        // Every automatic sign-out arrives via `schedule:run`. If this is red, nothing
        // else on this page matters and no amount of configuration will help.
        $beat = null;
        try {
            $beat = Cache::get('smartept:scheduler_heartbeat');
        } catch (\Throwable $e) {
            $this->warn('  cache unreadable (' . $e->getMessage() . ') — heartbeat cannot be checked');
        }

        $beatAge = $beat ? (int) now()->diffInMinutes(Carbon::parse($beat), true) : null;

        if (! $beat) {
            $blocked = true;
            $this->line('  <fg=red>[1] BACKGROUND SCHEDULER — NOT RUNNING</>  (no heartbeat has ever been recorded)');
        } elseif ($beatAge > 5) {
            $blocked = true;
            $this->line("  <fg=red>[1] BACKGROUND SCHEDULER — STOPPED</>  (last ran {$beatAge} minutes ago; it must run every minute)");
        } else {
            $this->line("  <fg=green>[1] Background scheduler</>        ok — last beat {$beatAge} minute(s) ago");
        }

        if ($blocked) {
            $this->line('');
            $this->line('      Nothing in routes/console.php is firing, so post-shift auto sign-out,');
            $this->line('      meeting auto-close, biometric auto-sync and the nightly attendance job');
            $this->line('      are ALL stopped. The settings are fine; the job that acts on them is not running.');
            $this->line('');
            $this->line('      Windows:  schtasks /Create /TN "SmartEPT Scheduler" /SC MINUTE /MO 1 /RU SYSTEM ^');
            $this->line('                  /TR "C:\\PHP\\php.exe C:\\smartept\\artisan schedule:run"');
            $this->line('      Linux:    * * * * * cd /var/www/smartept && php artisan schedule:run >> /dev/null 2>&1');
            $this->line('');
        }

        // ── Link 2: is the every-5-minute run being skipped by a stuck mutex? ──────────
        // withoutOverlapping() defaults to a 24-hour lock. A run killed by a deploy or a
        // PHP timeout never releases it, and `schedule:run` then skips the command without
        // printing anything at all — the failure mode that looks exactly like link 1.
        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'smartept:auto-logout'));

        if (! $event) {
            $blocked = true;
            $this->line('  <fg=red>[2] Schedule registration</>       MISSING — smartept:auto-logout is not scheduled in routes/console.php');
        } else {
            $locked = false;
            try {
                $locked = $event->mutex && $event->mutex->exists($event);
            } catch (\Throwable $e) {
                // A cache driver that cannot be read is already reported above.
            }

            if ($locked) {
                $blocked = true;
                $this->line('  <fg=red>[2] Overlap lock</>                HELD — every run is being skipped in silence');
                $this->line('      A previous run was killed and never released its lock. Laravel holds it for 24h.');
                $this->line('      Clear it:  php artisan schedule:clear-cache');
            } else {
                $this->line('  <fg=green>[2] Overlap lock</>                clear — the command is free to run');
            }
        }

        // ── Link 3: does the column the admin screen writes to actually exist? ─────────
        $onShifts = Schema::hasColumn('shifts', 'post_shift_auto_logout_minutes');
        $onPolicy = Schema::hasColumn('attendance_policies', 'post_shift_auto_logout_minutes');

        if (! $onShifts || ! $onPolicy) {
            $blocked = true;
            $this->line('  <fg=red>[3] Database columns</>            MISSING'
                . (! $onShifts ? ' shifts.post_shift_auto_logout_minutes' : '')
                . (! $onPolicy ? ' attendance_policies.post_shift_auto_logout_minutes' : ''));
            $this->line('      Run:  php artisan migrate');
        } else {
            $this->line('  <fg=green>[3] Database columns</>            present on shifts and attendance_policies');
        }

        // ── Link 5 (checked here, it frames every verdict below): the wall clock ───────
        // Shifts store a clock face. Comparing "has 18:30 passed?" against a UTC now() is a
        // straight 5h30m error for an India tenant and reads as "not due yet".
        $appTz = config('app.timezone', 'UTC');
        $companies = Employee::withoutGlobalScopes()->distinct()->pluck('company_id')->filter();
        $this->line('  <fg=green>[5] Clocks</>                      app timezone ' . $appTz . ' = ' . now()->toDateTimeString());
        foreach ($companies as $cid) {
            $tz = $this->companyTz($cid);
            $flag = $tz === $appTz && $appTz === 'UTC' ? '  <fg=yellow>← still on the UTC default; Organisation → Company → Timezone</>' : '';
            $this->line(sprintf('      company %-4s wall clock : %s  (%s)%s', $cid, $this->localNow($cid)->toDateTimeString(), $tz, $flag));
        }

        // ── Evidence: has this command EVER signed anyone out on this server? ─────────
        $lastAuto = EmployeeLoginSession::withoutGlobalScopes()
            ->where('logout_reason', 'POST_SHIFT_AUTO')->latest('logout_at')->first();
        $autoWeek = EmployeeLoginSession::withoutGlobalScopes()
            ->where('logout_reason', 'POST_SHIFT_AUTO')
            ->where('logout_at', '>=', now()->subDays(7))->count();

        $this->line('  <fg=green>[6] History</>                     '
            . ($lastAuto
                ? 'last auto sign-out ' . $lastAuto->logout_at?->toDateTimeString() . ' — ' . $autoWeek . ' in the last 7 days'
                : '<fg=yellow>NEVER — no session on this server has ever been closed by auto sign-out</>'));

        // ── Link 4 + 6: the per-session verdict ───────────────────────────────────────
        $this->line('');

        $now = $this->localNow(null);
        $rows = [];
        $notDue = 0;
        $minutesCache = [];

        EmployeeLoginSession::withoutGlobalScopes()
            ->with('employee.shift')
            ->whereNull('logout_at')
            ->whereNotNull('login_at')
            ->orderBy('login_at')
            ->chunkById(200, function ($sessions) use ($resolver, $now, &$rows, &$notDue, &$minutesCache) {
                foreach ($sessions as $session) {
                    $employee = $session->employee;
                    $who = $employee ? $employee->employee_code : '#' . $session->id;
                    $shift = $employee?->shift;

                    // The lookback the real command applies. Anything older is the nightly
                    // sweep's job, and if it is still open the nightly sweep is not running
                    // either — which is the same broken link 1.
                    $tooOld = $session->login_at->lessThan($now->copy()->subDays(self::AUTO_LOGOUT_LOOKBACK_DAYS));

                    $minutes = null;
                    if ($employee) {
                        $minutes = $minutesCache[$employee->id] ??= $this->resolveMinutes($employee, $resolver);
                    }

                    $cutoff = ($employee && $minutes !== null)
                        ? $this->cutoffFor($session, $employee, $minutes)
                        : null;

                    $verdict = match (true) {
                        ! $employee => 'session has no employee record — orphan row',
                        $tooOld => 'login is older than ' . self::AUTO_LOGOUT_LOOKBACK_DAYS
                            . ' days — outside auto sign-out; the NIGHTLY job owns it, and it has not run either',
                        $minutes === null && ! $employee->shift_id =>
                            'NOT CONFIGURED — no shift assigned to this employee, and no Attendance policy with a value is assigned',
                        $minutes === null =>
                            'NOT CONFIGURED — minutes blank on shift "' . ($shift?->name ?: $shift?->id) . '", and no assigned Attendance policy carries a value',
                        ! $cutoff => 'no shift end and no login day to measure from',
                        $this->localNow($employee->company_id)->lessThan($cutoff) =>
                            'not due yet — due ' . $cutoff->format('Y-m-d H:i'),
                        default => 'DUE ' . $cutoff->format('Y-m-d H:i')
                            . ' — should already be signed out; the command is not reaching it',
                    };

                    if (str_starts_with($verdict, 'not due yet') && ! $this->option('all')) {
                        $notDue++;
                        continue;
                    }

                    $rows[] = [
                        $who,
                        $shift ? ($shift->name ?: 'shift ' . $shift->id) : '— none —',
                        $shift?->end_time ?: '—',
                        $minutes === null ? 'not set' : $minutes . 'm',
                        $session->login_at->format('Y-m-d H:i'),
                        $verdict,
                    ];
                }
            });

        if ($rows) {
            $this->table(['Emp', 'Shift', 'ends', 'auto', 'open since', 'verdict'], $rows);
        } else {
            $this->info('  No open session is overdue for sign-out.');
        }

        if ($notDue) {
            $this->line("  ({$notDue} open session(s) inside their shift — run with --all to list them.)");
        }

        $this->line('');
        if ($blocked) {
            $this->error('  Fix the RED link above first. Every symptom — Available on the live board, a blank');
            $this->error('  sign-out time, the agent still signed in, the long day on the report — is downstream of it.');
        } else {
            $this->comment('  All six links are healthy. Any "DUE" row above means the close itself is failing —');
            $this->comment('  run:  php artisan smartept:auto-logout --explain');
        }
        $this->line('');

        return self::SUCCESS;
    }

    /** Identical resolution to AutoLogoutPostShift: shift wins, assigned policy is the fallback. */
    private function resolveMinutes(Employee $employee, PolicyResolver $resolver): ?int
    {
        $minutes = $employee->shift?->post_shift_auto_logout_minutes;

        if ($minutes === null) {
            $minutes = $resolver->resolvePolicy($employee, 'ATTENDANCE')['post_shift_auto_logout_minutes'] ?? null;
        }

        return $minutes === null ? null : max(0, (int) $minutes);
    }

    /** Identical cutoff to AutoLogoutPostShift, so this page cannot disagree with the job. */
    private function cutoffFor(EmployeeLoginSession $session, Employee $employee, int $minutes): ?Carbon
    {
        $loginDay = $session->login_at->toDateString();
        $shift = $employee->shift;

        if (! $shift || ! $shift->end_time) {
            return Carbon::parse($loginDay . ' 23:59:59')->addMinutes($minutes);
        }

        $end = Carbon::parse($loginDay . ' ' . $shift->end_time);

        if ($shift->crosses_midnight
            || ($shift->start_time && $end->lessThanOrEqualTo(Carbon::parse($loginDay . ' ' . $shift->start_time)))) {
            $end->addDay();
        }

        return $end->addMinutes($minutes);
    }
}
