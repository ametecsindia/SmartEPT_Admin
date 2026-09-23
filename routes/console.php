<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('smartept:about', function () {
    $this->info('SmartEPT Admin Server — MVP (M1–M6).');
    $this->line('Auth/RBAC · Company/Org · Employee/Device · Policy Engine · Attendance/Activity ·');
    $this->line('Presence/Screenshots · App/Website usage + enforcement · Dashboard/Reports ·');
    $this->line('Biometric integration · Scoring · Retention purge.');
})->purpose('Show SmartEPT server info');

// Nightly: complete yesterday's attendance sheet (auto-absent / half-day / stale
// sessions) FIRST, so the summaries that follow score a finished sheet.
// 23-Sep-2026: EVERY job in this file runs IN-PROCESS (Schedule::call), not as a spawned
// `php artisan` child — biometric auto-sync included, which is why punches only arrived after a
// manual "Sync now" (that button runs in-process; the scheduled copy never started). When the
// schedule runs inline from a web request (SchedulerKeepAlive::runInline) a Schedule::command
// has to launch a separate PHP CLI process from php-fpm/IIS, which can fail silently — while the
// in-process heartbeat closure keeps Troubleshooting green. Same command, same lock, no child.
// 23-Sep-2026 (Ejaz): 00:15 on EACH COMPANY'S clock (Organisation tab), not the server's. A
// dailyAt() is evaluated on the server timezone, so on a multi-company server "yesterday" and
// "00:15" were server time. Every 15 minutes, each company whose local time is 00:15–00:29
// gets its own run for its own local yesterday. No company timezone → server timezone.
Schedule::call(function () {
    \App\Models\Company::withoutGlobalScopes()->get(['id', 'timezone'])->each(function ($c) {
        $tz = $c->timezone && in_array($c->timezone, timezone_identifiers_list(), true) ? $c->timezone : config('app.timezone');
        $local = now($tz);
        if ($local->hour === 0 && $local->minute >= 15 && $local->minute < 30) {
            Artisan::call('smartept:mark-attendance', ['--company' => $c->id, '--date' => $local->copy()->subDay()->toDateString()]);
        }
    });
})->everyFifteenMinutes()->name('mark-attendance')->withoutOverlapping(30);
Schedule::call(fn () => Artisan::call('smartept:daily-summary'))->name('daily-summary')->dailyAt('00:30');
Schedule::call(fn () => Artisan::call('smartept:purge-expired'))->name('purge-expired')->dailyAt('02:00');

// R2-1: daily licence phone-home to SmartEPT Central (metadata only — the hard wall).
// withoutOverlapping (21-Aug-2026): the command walks every tenant row and each
// call blocks for up to 10s on an unreachable Central, so on an offline install
// with a dozen tenants a run can still be going when the next one starts.
// 2-Sep-2026: bounded — see the note on smartept:auto-logout below. An unbounded lock left
// by a killed run would skip the NEXT day's phone-home too, and the licence wall is the last
// thing that should fail silently. 2h is far longer than the walk can legitimately take.
Schedule::call(fn () => Artisan::call('smartept:validate-license'))->name('validate-license')->dailyAt('01:00')->withoutOverlapping(120);

// R2-2: ops alerts — silent-agent sweep + violation-spike watch (admin emails),
// and a morning digest of application errors so problems never hide in the log.
Schedule::call(fn () => Artisan::call('smartept:alerts'))->name('alerts')->everyThirtyMinutes();
// 23-Sep-2026: hourly tick; the command sends only at the hour chosen in Audit & Ops → Notifications.
Schedule::call(fn () => Artisan::call('smartept:error-digest'))->name('error-digest')->hourly();

// R2-4: nightly gzipped data backup into storage/app/backups (keeps newest 14).
Schedule::call(fn () => Artisan::call('smartept:backup-database'))->name('backup-database')->dailyAt('01:30');

// 17-Jul: outbound integration push (SmartEPT → SmartPRS etc.) — previous day at 02:00.
Schedule::call(fn () => Artisan::call('smartept:push-integrations'))->name('push-integrations')->dailyAt('02:00');

// Cloud biometric punch import (eTimeOffice-style APIs). Ejaz 18-Jul: sync must be
// CONTINUOUS like the heartbeat, not hourly — every 5 minutes for every device with
// automatic sync ticked, so the Biometric Gate reacts to punches within minutes.
Schedule::call(fn () => Artisan::call('smartept:biometric-sync'))->name('biometric-sync')->everyFiveMinutes();
Schedule::call(fn () => Artisan::call('smartept:build-archives'))->name('build-archives')->everyMinute()->withoutOverlapping(15); // Employee Archive ZIP builder (24-Jul); bounded 2-Sep-2026

// Section 2: advance meeting statuses + auto-close meeting sessions at the scheduled
// end (so "Meeting" status ends on time even if the employee never presses End).
Schedule::call(fn () => Artisan::call('smartept:close-meetings'))->name('close-meetings')->everyMinute();

// 19-Aug-2026 (Ejaz): post-shift auto logout. mark-attendance already closes forgotten
// sessions, but only at 00:15 the next day — long enough for a stale check_out_at to reach
// the productivity report (the 596% AI0043 row). This runs every 5 minutes and signs the
// agent out AT shift end + the configured minutes. No-op for shifts/policies where the
// minutes are not set, so it is inert until an admin turns it on.
// 2-Sep-2026: the lock is BOUNDED at 10 minutes. `withoutOverlapping()` with no argument
// takes a TWENTY-FOUR HOUR lock, and a run killed mid-flight — a deploy, an IIS app-pool
// recycle, PHP max_execution_time, a server reboot — never releases it. `schedule:run` then
// skips the command in silence for the rest of the day: no output, no error, no log line.
// That is indistinguishable from "the feature does not work" and is the shape of the
// symptom Ejaz has now reported three times. A bound just longer than the interval means a
// crashed run self-heals on the next pass. `smartept:why-no-signout` reports the lock state.
Schedule::call(fn () => Artisan::call('smartept:auto-logout'))->everyFiveMinutes()->name('auto-logout')->withoutOverlapping(10); // in-process — see mark-attendance note

// QA Phase 3 (B6): scheduler self-diagnosis. A 1-minute closure stamps a heartbeat
// cache key; Help → Troubleshooting turns RED when it goes stale — the tell-tale that
// Windows Task Scheduler / cron is NOT running `php artisan schedule:run`, which would
// otherwise silently stop biometric auto-sync, meeting auto-close and nightly attendance.
Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put('smartept:scheduler_heartbeat', now()->toDateTimeString(), now()->addMinutes(30));
// ⚠ The bound matters most HERE. An unbounded lock on the heartbeat would stop the beat for
// 24h after one killed run, turning Troubleshooting RED while the scheduler is in fact fine —
// a false alarm on the one indicator everything else is diagnosed from (2-Sep-2026).
})->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping(5);

// LiveView Phase 4 (14-Sep-2026): end sessions the Agent stopped heartbeating on —
// see EndStaleLiveViewSessions's docblock. Bounded like every other sweep here
// (2-Sep-2026 rule): a killed run must self-heal on the next minute, not lock stale
// concurrency slots for 24h.
Schedule::call(fn () => Artisan::call('smartept:end-stale-liveview-sessions'))->name('end-stale-liveview-sessions')->everyMinute()->withoutOverlapping(2);

// Live-board self-heal (Admin #3/#4): close any break/meeting status segment left open
// across a day boundary (agent killed mid-break → a 16-hour "On break" ghost) so the live
// dashboard never shows an impossible multi-hour break. The dashboard also self-heals on
// read; this covers tenants nobody is viewing right now.
Schedule::call(function () {
    app(\App\Services\StatusService::class)->closeStaleOpenSegments(
        \App\Models\Employee::withoutGlobalScopes()->pluck('id')->all(),
        now()->startOfDay()
    );
})->everyFifteenMinutes()->name('close-stale-status')->withoutOverlapping(30); // bounded 2-Sep-2026

// TEMP DIAG 22-Sep-2026 — remove after auto sign-out is confirmed. Writes what the scheduler
// itself sees (not a manual run) to storage/logs/autologout-diag.txt every minute.
Schedule::call(function () {
    $out = '=== ' . now()->toDateTimeString() . ' (tz ' . config('app.timezone') . ', php ' . PHP_BINARY . ")\n";
    foreach ([['smartept:why-no-signout', ['--all' => true]], ['smartept:auto-logout', ['--dry-run' => true, '--explain' => true]]] as [$cmd, $args]) {
        try { \Illuminate\Support\Facades\Artisan::call($cmd, $args); $out .= "--- {$cmd}\n" . \Illuminate\Support\Facades\Artisan::output(); }
        catch (\Throwable $e) { $out .= "--- {$cmd} THREW: " . $e->getMessage() . "\n"; }
    }
    $db = \Illuminate\Support\Facades\DB::class;
    $dump = function ($title, $q) use (&$out) { $out .= "--- {$title}\n"; try { foreach ($q() as $r) { $out .= json_encode($r) . "\n"; } } catch (\Throwable $e) { $out .= 'THREW: ' . $e->getMessage() . "\n"; } };
    $dump('show create shifts', fn () => $db::select('SHOW CREATE TABLE shifts'));
    $dump('shifts', fn () => $db::table('shifts')->get());
    $dump('companies', fn () => $db::table('companies')->get(['id','name']));
    $dump('employees', fn () => $db::table('employees')->whereNull('deleted_at')->get(['id','company_id','employee_code','first_name','shift_id']));
    $dump('sessions since 20-Sep', fn () => $db::table('employee_login_sessions')->where('login_at','>=','2026-09-20')->orderBy('id')->get());
    $dump('recent auto logouts', fn () => $db::table('employee_login_sessions')->where('logout_reason','POST_SHIFT_AUTO')->orderByDesc('id')->limit(10)->get());
    file_put_contents(storage_path('logs/autologout-diag.txt'), $out);
})->everyMinute()->name('temp-autologout-diag');
