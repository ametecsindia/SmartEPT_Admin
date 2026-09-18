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
Schedule::command('smartept:mark-attendance')->dailyAt('00:15');
Schedule::command('smartept:daily-summary')->dailyAt('00:30');
Schedule::command('smartept:purge-expired')->dailyAt('02:00');

// R2-1: daily licence phone-home to SmartEPT Central (metadata only — the hard wall).
// withoutOverlapping (21-Aug-2026): the command walks every tenant row and each
// call blocks for up to 10s on an unreachable Central, so on an offline install
// with a dozen tenants a run can still be going when the next one starts.
// 2-Sep-2026: bounded — see the note on smartept:auto-logout below. An unbounded lock left
// by a killed run would skip the NEXT day's phone-home too, and the licence wall is the last
// thing that should fail silently. 2h is far longer than the walk can legitimately take.
Schedule::command('smartept:validate-license')->dailyAt('01:00')->withoutOverlapping(120);

// R2-2: ops alerts — silent-agent sweep + violation-spike watch (admin emails),
// and a morning digest of application errors so problems never hide in the log.
Schedule::command('smartept:alerts')->everyThirtyMinutes();
Schedule::command('smartept:error-digest')->dailyAt('07:30');

// R2-4: nightly gzipped data backup into storage/app/backups (keeps newest 14).
Schedule::command('smartept:backup-database')->dailyAt('01:30');

// 17-Jul: outbound integration push (SmartEPT → SmartPRS etc.) — previous day at 02:00.
Schedule::command('smartept:push-integrations')->dailyAt('02:00');

// Cloud biometric punch import (eTimeOffice-style APIs). Ejaz 18-Jul: sync must be
// CONTINUOUS like the heartbeat, not hourly — every 5 minutes for every device with
// automatic sync ticked, so the Biometric Gate reacts to punches within minutes.
Schedule::command('smartept:biometric-sync')->everyFiveMinutes();
Schedule::command('smartept:build-archives')->everyMinute()->withoutOverlapping(15); // Employee Archive ZIP builder (24-Jul); bounded 2-Sep-2026

// Section 2: advance meeting statuses + auto-close meeting sessions at the scheduled
// end (so "Meeting" status ends on time even if the employee never presses End).
Schedule::command('smartept:close-meetings')->everyMinute();

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
Schedule::command('smartept:auto-logout')->everyFiveMinutes()->withoutOverlapping(10);

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
Schedule::command('smartept:end-stale-liveview-sessions')->everyMinute()->withoutOverlapping(2);

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
