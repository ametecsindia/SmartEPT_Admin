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
Schedule::command('smartept:validate-license')->dailyAt('01:00');

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

// Section 2: advance meeting statuses + auto-close meeting sessions at the scheduled
// end (so "Meeting" status ends on time even if the employee never presses End).
Schedule::command('smartept:close-meetings')->everyMinute();

// QA Phase 3 (B6): scheduler self-diagnosis. A 1-minute closure stamps a heartbeat
// cache key; Help → Troubleshooting turns RED when it goes stale — the tell-tale that
// Windows Task Scheduler / cron is NOT running `php artisan schedule:run`, which would
// otherwise silently stop biometric auto-sync, meeting auto-close and nightly attendance.
Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put('smartept:scheduler_heartbeat', now()->toDateTimeString(), now()->addMinutes(30));
})->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();
