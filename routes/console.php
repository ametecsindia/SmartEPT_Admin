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
Schedule::command('smartept:validate-license')->dailyAt('01:00')->withoutOverlapping();

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
Schedule::command('smartept:build-archives')->everyMinute()->withoutOverlapping(); // Employee Archive ZIP builder (24-Jul)

// Section 2: advance meeting statuses + auto-close meeting sessions at the scheduled
// end (so "Meeting" status ends on time even if the employee never presses End).
Schedule::command('smartept:close-meetings')->everyMinute();

// 19-Aug-2026 (Ejaz): post-shift auto logout. mark-attendance already closes forgotten
// sessions, but only at 00:15 the next day — long enough for a stale check_out_at to reach
// the productivity report (the 596% AI0043 row). This runs every 5 minutes and signs the
// agent out AT shift end + the configured minutes. No-op for shifts/policies where the
// minutes are not set, so it is inert until an admin turns it on.
Schedule::command('smartept:auto-logout')->everyFiveMinutes()->withoutOverlapping();

// QA Phase 3 (B6): scheduler self-diagnosis. A 1-minute closure stamps a heartbeat
// cache key; Help → Troubleshooting turns RED when it goes stale — the tell-tale that
// Windows Task Scheduler / cron is NOT running `php artisan schedule:run`, which would
// otherwise silently stop biometric auto-sync, meeting auto-close and nightly attendance.
Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put('smartept:scheduler_heartbeat', now()->toDateTimeString(), now()->addMinutes(30));
})->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();

// Live-board self-heal (Admin #3/#4): close any break/meeting status segment left open
// across a day boundary (agent killed mid-break → a 16-hour "On break" ghost) so the live
// dashboard never shows an impossible multi-hour break. The dashboard also self-heals on
// read; this covers tenants nobody is viewing right now.
Schedule::call(function () {
    app(\App\Services\StatusService::class)->closeStaleOpenSegments(
        \App\Models\Employee::withoutGlobalScopes()->pluck('id')->all(),
        now()->startOfDay()
    );
})->everyFifteenMinutes()->name('close-stale-status')->withoutOverlapping();
