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
