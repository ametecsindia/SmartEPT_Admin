<?php

namespace App\Support;

use App\Services\UpdateClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keep `artisan schedule:work` alive from the application itself (Ejaz, 2-Sep-2026:
 * "no manual running of install.bat — everything should be included in the updated package").
 *
 * WHY THIS EXISTS. Every background job in SmartEPT runs through `artisan schedule:run`:
 * post-shift auto sign-out, meeting auto-close, biometric auto-sync, the nightly attendance
 * sheet, licence phone-home, backups, retention purge. On Windows that needs a Task Scheduler
 * job, on Linux a cron line — and until 2-Sep-2026 creating it was a step a human had to type
 * out of INSTALL-GUIDE.md. It was being skipped, so on those servers ALL of the above were
 * dead while every screen still looked correct. That is the whole of the "auto sign-out does
 * not work" report, three times over.
 *
 * `INSTALL.bat` now creates the task, but an installer only fixes FUTURE installs: a
 * self-update unpacks files, it does not re-run the installer. Every server already out there
 * would still need the command typed by hand. So the repair has to live in code that ships
 * INSIDE the package and runs on the client the moment they take the update — which means the
 * application boot, the one thing guaranteed to run.
 *
 * WHAT IT DOES. On a web request, if the scheduler heartbeat is stale, spawn a detached
 * `php artisan schedule:work` (Laravel's own supervisor loop — it calls `schedule:run` every
 * minute for as long as it lives) and let it stamp the heartbeat. On the next request the beat
 * is fresh and this costs one cache read.
 *
 * WHAT IT IS NOT. It is not a replacement for the scheduled task, and it does not pretend to
 * be: a spawned process dies with an app-pool recycle or a reboot, and nothing runs again
 * until somebody loads a page. It is a floor, not a ceiling — the Task Scheduler job is still
 * the right answer and Help → Troubleshooting still tells the admin to create it. What this
 * guarantees is that a client is never again silently getting NOTHING.
 *
 * WHY NOT create the scheduled task from here instead: registering a SYSTEM task needs
 * elevation, and the IIS application-pool identity does not have it. Spawning a process as
 * the web user needs no privileges at all, so it works everywhere without asking.
 *
 * SAFETY. Never throws (a page load must not fail because of this), never runs in console
 * (`schedule:work` would re-enter it), never runs when a real cron/Task Scheduler job is
 * already beating, and holds a cache lock so concurrent workers spawn one process, not eight.
 * Turn it off with `SMARTEPT_SCHEDULER_KEEPALIVE=false` on a server with a healthy cron.
 */
class SchedulerKeepAlive
{
    /** Written every minute by the `scheduler-heartbeat` closure in routes/console.php. */
    private const HEARTBEAT = 'smartept:scheduler_heartbeat';

    /** One spawn attempt at a time, and at most one every RETRY_SECONDS. */
    private const LOCK = 'smartept:scheduler_spawn_lock';

    /** Matches DiagnosticsController::checkScheduler(), so both agree on "stopped". */
    private const STALE_MINUTES = 5;

    /** Long enough for a spawned worker to stamp its first beat before anyone tries again. */
    private const RETRY_SECONDS = 180;

    /** Called from AppServiceProvider::boot(). Cheap and silent on a healthy server. */
    public static function ensure(): void
    {
        // 22-Sep-2026: run the schedule INSIDE the app. Spawning schedule:work needs a CLI
        // php the web SAPI can find and a process the host does not reap; on laragon it
        // failed 369 times in a row and on admin.smartept.com auto sign-out and the nightly
        // attendance sheet both stopped with nobody told. Agents heartbeat every ~30s, so
        // there is always a request to piggy-back on while anyone is signed in — which is
        // exactly when auto sign-out is needed. Runs after the response is sent.
        if (! app()->runningInConsole() && config('smartept.scheduler_keepalive', true)) {
            app()->terminating(static fn () => self::runInline());
        }

        try {
            if (! self::shouldSpawn()) {
                return;
            }

            // Claim the attempt BEFORE the work, so eight concurrent requests spawn one worker.
            if (! Cache::add(self::LOCK, 1, self::RETRY_SECONDS)) {
                return;
            }

            $client = app(UpdateClient::class);
            $canSpawn = $client->canSpawn();
            $php = $canSpawn ? $client->phpBinary() : null;

            // No CLI php, or exec/popen disabled in php.ini. Nothing to do — Troubleshooting
            // stays red and tells the admin to create the scheduled task, which is correct.
            // 16-Sep-2026: this used to fail completely silently, so a server where it
            // never once worked (Ejaz's laragon box — canSpawn()/phpBinary() both looked
            // fine on paper, yet no heartbeat was ever recorded) gave nobody anything to
            // go on. One log line per reason, once per RETRY_SECONDS window (the lock
            // above already rate-limits this), so the NEXT silent failure is diagnosable
            // from storage/logs/laravel.log instead of requiring a fresh investigation.
            if ($php) {
                Log::info('SchedulerKeepAlive: spawning schedule:work', ['php' => $php]);
                self::spawn($php);
            } elseif (! $canSpawn) {
                Log::warning('SchedulerKeepAlive: cannot spawn — popen and proc_open are both '
                    . 'disabled in php.ini. Create the Windows Scheduled Task / cron job manually.');
            } else {
                Log::warning('SchedulerKeepAlive: could not locate a PHP CLI binary '
                    . '(checked PHP_BINDIR and PHP_BINARY). Set SMARTEPT_PHP_BINARY in .env to '
                    . 'the full path of php.exe, or create the scheduled task manually.');
            }
        } catch (\Throwable $e) {
            // A page load must never fail because of this. On a fresh install the cache table
            // does not exist yet and Cache::get throws — that is a normal path, not an error.
            // Still worth a log line: this catch is exactly where a real bug would otherwise
            // hide forever, same lesson as the two branches above.
            try {
                Log::warning('SchedulerKeepAlive: ensure() threw, skipping this attempt', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // Logging itself is unavailable (e.g. unwritable storage/logs — see
                // smartept_server_permissions_500s). Nothing left to do but let the request
                // continue; Troubleshooting still shows the scheduler as red.
            }
        }
    }

    /** Set by runInline(), so an inline run is never mistaken for a real cron beating. */
    private const INLINE_AT = 'smartept:scheduler_inline_at';

    /**
     * `schedule:run` once per minute from the tail of a web request, unless a real cron /
     * Task Scheduler / schedule:work is already beating. Never throws.
     *
     * ponytail: request-driven — with zero traffic (every agent offline at night) nothing
     * runs, so the 00:15 mark-attendance can be missed; the next auto-logout pass and the
     * Task Scheduler / cron entry still cover that. Needs no binary, no privilege, no setup.
     */
    public static function runInline(): void
    {
        try {
            $beat = Cache::get(self::HEARTBEAT);
            $inline = Cache::get(self::INLINE_AT);
            $externalAlive = $beat && Carbon::parse($beat)->greaterThan(now()->subMinutes(2))
                && ! ($inline && Carbon::parse($inline)->greaterThan(now()->subMinutes(2)));
            if ($externalAlive || ! Cache::add('smartept:scheduler_inline_lock', 1, 55)) {
                return;
            }

            Cache::put(self::INLINE_AT, now()->toDateTimeString(), now()->addMinutes(30));
            ignore_user_abort(true);
            @set_time_limit(300);
            \Illuminate\Support\Facades\Artisan::call('schedule:run');
        } catch (\Throwable $e) {
            try {
                Log::warning('SchedulerKeepAlive: inline schedule:run failed', ['error' => $e->getMessage()]);
            } catch (\Throwable $ignored) {
            }
        }
    }

    /**
     * Is the scheduler silent right now? Separated from ensure() so it can be asserted in a
     * test without spawning a real process.
     */
    public static function shouldSpawn(): bool
    {
        // `schedule:work` itself boots the app; spawning from console would recurse. Artisan
        // commands are also short-lived, so there is no page load waiting on this anyway.
        if (app()->runningInConsole()) {
            return false;
        }

        return config('smartept.scheduler_keepalive', true) && self::schedulerIsSilent();
    }

    /**
     * Has nothing run `schedule:run` in the last few minutes? True means no cron and no Task
     * Scheduler job — the state this class exists to repair. Kept free of the console and
     * config guards above so the freshness rule itself can be asserted from a test, which
     * necessarily runs in console.
     */
    public static function schedulerIsSilent(): bool
    {
        $beat = Cache::get(self::HEARTBEAT);

        return ! ($beat && Carbon::parse($beat)->greaterThan(now()->subMinutes(self::STALE_MINUTES)));
    }

    /**
     * Launch `schedule:work` detached so it outlives this request. Same shape as
     * UpdateClient::spawn(), which has been doing exactly this on client servers since 1-Sep.
     * Output goes nowhere on purpose: the heartbeat IS the diagnostic, and a worker that
     * appends a line a minute forever would be a disk leak nobody rotates.
     */
    private static function spawn(string $php): void
    {
        $windows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');
        $artisan = base_path('artisan');

        $cmd = $windows
            ? 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($artisan) . ' schedule:work > NUL 2>&1'
            : escapeshellarg($php) . ' ' . escapeshellarg($artisan) . ' schedule:work > /dev/null 2>&1 &';

        if ($windows) {
            if ($handle = popen('cmd /C ' . $cmd, 'r')) {
                pclose($handle);
            }

            return;
        }

        $pipes = [];
        if ($process = proc_open($cmd, [], $pipes)) {
            proc_close($process);
        }
    }
}
