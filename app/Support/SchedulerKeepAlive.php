<?php

namespace App\Support;

use App\Services\UpdateClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
        try {
            if (! self::shouldSpawn()) {
                return;
            }

            // Claim the attempt BEFORE the work, so eight concurrent requests spawn one worker.
            if (! Cache::add(self::LOCK, 1, self::RETRY_SECONDS)) {
                return;
            }

            $client = app(UpdateClient::class);
            $php = $client->canSpawn() ? $client->phpBinary() : null;

            // No CLI php, or exec/popen disabled in php.ini. Nothing to do — Troubleshooting
            // stays red and tells the admin to create the scheduled task, which is correct.
            if ($php) {
                self::spawn($php);
            }
        } catch (\Throwable $e) {
            // A page load must never fail because of this. On a fresh install the cache table
            // does not exist yet and Cache::get throws — that is a normal path, not an error.
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
