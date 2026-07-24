<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDevice;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-app System Health / Diagnostics (Ametecs troubleshooting-in-app standard).
 *
 * Purpose: let a NON-TECHNICAL operator at a client site press one button and
 * see, in plain language, whether SmartEPT is healthy — and if not, exactly
 * which Known-Issue fix to follow. No terminal, no log-diving, no engineer.
 *
 * Every check returns: key, label, status (ok|warn|down), detail (plain
 * language), and `fix` — the id of the Help → Known-Issues card that explains
 * how to resolve it. The UI turns each amber/red row into a link to that card.
 */
class DiagnosticsController extends Controller
{
    /** GET /api/ops/diagnostics — run every self-check and return the results. */
    public function checks(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $checks = [
            $this->checkDatabase(),
            $this->checkSanctumTable(),
            $this->checkMigrations(),
            $this->checkScheduler(),
            $this->checkBiometricSync($companyId),
            $this->checkEvidenceWritable(),
            $this->checkScreenshotEvidence($companyId),
            $this->checkStoragePaused(),
            $this->checkOpcache(),
            $this->checkAgentHeartbeats($companyId),
            $this->checkMail(),
            $this->checkRecentErrors(),
        ];

        $worst = 'ok';
        foreach ($checks as $c) {
            if ($c['status'] === 'down') { $worst = 'down'; break; }
            if ($c['status'] === 'warn') { $worst = 'warn'; }
        }

        return response()->json([
            'overall'     => $worst,
            'checked_at'  => now()->toDateTimeString(),
            'checks'      => $checks,
        ]);
    }

    /**
     * GET /api/ops/logs?lines=N — the last N lines of storage/logs/laravel.log,
     * so the operator can read (and copy for the developer) recent errors
     * without ever opening a terminal.
     */
    public function logs(Request $request): JsonResponse
    {
        $lines = min(max((int) $request->integer('lines', 200), 20), 500);
        $path  = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return response()->json([
                'exists' => false,
                'path'   => 'storage/logs/laravel.log',
                'text'   => '',
                'note'   => 'No log file yet — nothing has been written. That is normal on a fresh install.',
            ]);
        }

        return response()->json([
            'exists'      => true,
            'path'        => 'storage/logs/laravel.log',
            'size_human'  => $this->human((int) filesize($path)),
            'lines'       => $lines,
            'text'        => $this->tail($path, $lines),
        ]);
    }

    // ---------------------------------------------------------------------
    // Individual checks
    // ---------------------------------------------------------------------

    private function checkDatabase(): array
    {
        try {
            $conn   = DB::connection();
            $driver = $conn->getDriverName();
            $conn->getPdo();                       // force a real connection
            $name   = $conn->getDatabaseName();

            if ($driver === 'sqlite') {
                return $this->row('database', 'Database connection', 'down',
                    'The app is connected to a local SQLite file instead of your MySQL database. '
                    . 'Agents cannot be verified, so screenshots and activity are being rejected.',
                    'kb-db');
            }

            return $this->row('database', 'Database connection', 'ok',
                "Connected to the {$driver} database \"{$name}\".");
        } catch (\Throwable $e) {
            return $this->row('database', 'Database connection', 'down',
                'Could not connect to the database. The MySQL service may be stopped, or the '
                . 'login details in .env may be wrong.',
                'kb-db');
        }
    }

    private function checkSanctumTable(): array
    {
        try {
            $ok = Schema::hasTable('personal_access_tokens');
        } catch (\Throwable $e) {
            $ok = false;
        }

        return $ok
            ? $this->row('sanctum', 'Agent login token table', 'ok',
                'The login-token table is present — agents can sign in.')
            : $this->row('sanctum', 'Agent login token table', 'down',
                'The login-token table is missing, so agents cannot sign in and no data will arrive. '
                . 'Run the database update (migrate.bat).',
                'kb-migrate');
    }

    private function checkMigrations(): array
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = collect(glob(database_path('migrations/*.php')) ?: [])
                ->map(fn ($p) => basename($p, '.php'))->all();
            $pending = array_values(array_diff($files, $ran));

            if (count($pending) === 0) {
                return $this->row('migrations', 'Database updates', 'ok',
                    'All database updates have been applied.');
            }

            return $this->row('migrations', 'Database updates', 'warn',
                count($pending) . ' database update(s) have not been applied yet. New features may be '
                . 'missing or a screen may error until you run migrate.bat.',
                'kb-migrate');
        } catch (\Throwable $e) {
            return $this->row('migrations', 'Database updates', 'warn',
                'Could not check for pending database updates (the database may be unreachable).',
                'kb-db');
        }
    }

    private function checkEvidenceWritable(): array
    {
        $root = config('filesystems.disks.evidence.root') ?: storage_path('app');
        $probe = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.smartept_write_test';

        try {
            if (! is_dir($root)) {
                return $this->row('storage', 'Evidence storage folder', 'down',
                    "The evidence folder does not exist: {$root}. Screenshots and webcam photos cannot be saved.",
                    'kb-storage');
            }
            if (@file_put_contents($probe, 'ok') === false) {
                return $this->row('storage', 'Evidence storage folder', 'down',
                    "The evidence folder is not writable: {$root}. Screenshots cannot be saved — the "
                    . 'folder permissions or the disk may be the problem.',
                    'kb-storage');
            }
            @unlink($probe);

            $free = @disk_free_space($root);
            if ($free !== false && $free < 536870912) {   // < 512 MB
                return $this->row('storage', 'Evidence storage folder', 'warn',
                    "The evidence folder ({$root}) is writable but the disk is nearly full ("
                    . $this->human((int) $free) . ' free). Free up space or move storage to a NAS/cloud.',
                    'kb-storage');
            }

            return $this->row('storage', 'Evidence storage folder', 'ok',
                "Writable — evidence is saved to {$root}.");
        } catch (\Throwable $e) {
            return $this->row('storage', 'Evidence storage folder', 'down',
                "Could not check the evidence folder ({$root}).",
                'kb-storage');
        }
    }

    private function checkStoragePaused(): array
    {
        try {
            $paused = Schema::hasTable('settings') && Setting::get('storage_paused') === '1';
        } catch (\Throwable $e) {
            $paused = false;
        }

        return $paused
            ? $this->row('storage_paused', 'Evidence recording', 'warn',
                'Screenshot & evidence storage is PAUSED. This usually means the licence has not been '
                . 'validated. Enter/validate your licence key to resume recording.',
                'kb-license')
            : $this->row('storage_paused', 'Evidence recording', 'ok',
                'Evidence recording is active.');
    }

    private function checkOpcache(): array
    {
        if (! function_exists('opcache_get_status')) {
            return $this->row('opcache', 'PHP code cache (OPcache)', 'ok',
                'OPcache is not installed — PHP always reads the latest files.');
        }

        $status = @opcache_get_status(false);
        if (! $status || empty($status['opcache_enabled'])) {
            return $this->row('opcache', 'PHP code cache (OPcache)', 'ok',
                'OPcache is off — PHP always reads the latest files.');
        }

        $cfg = @opcache_get_configuration();
        $validate = $cfg['directives']['opcache.validate_timestamps'] ?? true;

        if ($validate === false || $validate === 0 || $validate === '0') {
            return $this->row('opcache', 'PHP code cache (OPcache)', 'warn',
                'OPcache is serving a frozen copy of the code (validate_timestamps is OFF). Fixes and '
                . 'setting changes will NOT take effect until PHP is fully restarted. Set '
                . 'opcache.validate_timestamps=1 in php.ini, then Laragon Stop All then Start All.',
                'kb-opcache');
        }

        return $this->row('opcache', 'PHP code cache (OPcache)', 'ok',
            'OPcache is on and re-reads changed files — updates take effect normally.');
    }

    private function checkAgentHeartbeats(?int $companyId): array
    {
        try {
            $base = EmployeeDevice::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId));

            $total  = (clone $base)->count();
            $recent = (clone $base)->where('last_heartbeat_at', '>=', now()->subMinutes(10))->count();
            $last   = (clone $base)->max('last_heartbeat_at');

            if ($total === 0) {
                return $this->row('agents', 'Agent check-ins', 'warn',
                    'No monitored PCs have registered yet. Install and pair the SmartEPT agent on the '
                    . 'computers you want to track.',
                    'kb-agent-install');
            }
            if ($recent > 0) {
                return $this->row('agents', 'Agent check-ins', 'ok',
                    "{$recent} of {$total} agent(s) checked in within the last 10 minutes.");
            }

            $when = $last ? date('d M Y H:i', strtotime((string) $last)) : 'never';

            return $this->row('agents', 'Agent check-ins', 'warn',
                "No agent has checked in during the last 10 minutes (last was {$when}). The PCs may be off, "
                . 'or the agent may have been stopped. Recorded data syncs automatically when they return.',
                'kb-agent-silent');
        } catch (\Throwable $e) {
            return $this->row('agents', 'Agent check-ins', 'warn',
                'Could not read agent check-ins (the database may be unreachable).',
                'kb-db');
        }
    }

    private function checkMail(): array
    {
        $mailer = config('mail.default');
        $host   = config("mail.mailers.{$mailer}.host");

        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            return $this->row('mail', 'Email sending', 'warn',
                'Email is not actually being sent — it is only written to the log. Credential and alert '
                . 'emails will not reach anyone. Set real SMTP details in .env (MAIL_MAILER=smtp).',
                'kb-mail');
        }

        if ($mailer === 'smtp' && ! $host) {
            return $this->row('mail', 'Email sending', 'warn',
                'SMTP is selected but no mail host is set, so emails cannot be sent. Add MAIL_HOST in .env.',
                'kb-mail');
        }

        return $this->row('mail', 'Email sending', 'ok',
            'Email sending is configured' . ($host ? " (via {$host})." : '.'));
    }

    private function checkRecentErrors(): array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return $this->row('errors', 'Recent errors', 'ok',
                'No error log yet — nothing has gone wrong.');
        }

        $tail = $this->tail($path, 400);
        $cutoff = now()->subHour();
        $count = 0;

        foreach (preg_split('/\r?\n/', $tail) as $line) {
            // Laravel line: [2026-07-19 14:03:11] production.ERROR: ...
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*?\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $m)) {
                try {
                    if (\Illuminate\Support\Carbon::parse($m[1])->greaterThanOrEqualTo($cutoff)) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    // ignore unparseable timestamp
                }
            }
        }

        return $count === 0
            ? $this->row('errors', 'Recent errors', 'ok',
                'No errors logged in the last hour.')
            : $this->row('errors', 'Recent errors', 'warn',
                "{$count} error(s) were logged in the last hour. Open the Log viewer below and use "
                . '"Copy for developer" if you need help.',
                'kb-500');
    }

    /**
     * QA Phase 3 (B6): the background scheduler heartbeat. A 1-minute closure in
     * routes/console.php stamps a cache key; if it goes stale the OS is not running
     * `php artisan schedule:run`, so biometric auto-sync + nightly jobs have stopped.
     */
    private function checkScheduler(): array
    {
        try {
            $beat = \Illuminate\Support\Facades\Cache::get('smartept:scheduler_heartbeat');
        } catch (\Throwable $e) {
            $beat = null;
        }

        if (! $beat) {
            return $this->row('scheduler', 'Background scheduler', 'warn',
                'The 1-minute background scheduler has not checked in yet. If this does not turn green '
                . 'within a couple of minutes, Windows Task Scheduler (or cron) is not running '
                . '"php artisan schedule:run" — biometric auto-sync, meeting auto-close and the nightly '
                . 'attendance job will not run.',
                'kb-scheduler');
        }

        try {
            $age = (int) now()->diffInMinutes(\Illuminate\Support\Carbon::parse($beat), true);
        } catch (\Throwable $e) {
            $age = 999;
        }

        if ($age > 5) {
            return $this->row('scheduler', 'Background scheduler', 'down',
                "The background scheduler last ran {$age} minute(s) ago — it should run every minute. "
                . 'Windows Task Scheduler is not running "php artisan schedule:run", so biometric '
                . 'auto-sync and the nightly attendance job have stopped. Start the scheduled task, '
                . 'then re-check.',
                'kb-scheduler');
        }

        return $this->row('scheduler', 'Background scheduler', 'ok',
            'The background scheduler ran within the last few minutes — automatic sync and nightly jobs are active.');
    }

    /**
     * QA Phase 3 (B6): cloud biometric auto-sync coverage. Surfaces devices that
     * have stopped syncing (stale) or were auto-disabled after repeated failures,
     * so a silent feed outage is visible without reading logs.
     */
    private function checkBiometricSync(?int $companyId): array
    {
        try {
            if (! Schema::hasTable('biometric_devices')) {
                return $this->row('biometric_sync', 'Biometric auto-sync', 'ok',
                    'No biometric devices are configured yet.');
            }

            $base = \App\Models\BiometricDevice::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId));

            $errored = (clone $base)->where('status', 'ERROR')->count();
            $active  = (clone $base)->where('status', 'ACTIVE')->count();

            if ($active === 0) {
                return $errored > 0
                    ? $this->row('biometric_sync', 'Biometric auto-sync', 'warn',
                        "{$errored} biometric device(s) were switched OFF after repeated sync failures. "
                        . 'Check the API credentials / endpoint in Biometric setup, then re-enable them.',
                        'kb-biometric-sync')
                    : $this->row('biometric_sync', 'Biometric auto-sync', 'ok',
                        'No active cloud biometric devices — nothing to sync automatically.');
            }

            // Admin #1/2: the "I have to sync manually" case — every active device is set to
            // MANUAL, so nothing is due for the scheduler and punches only import on "Sync now".
            $autoActive = (clone $base)->where('status', 'ACTIVE')
                ->where(fn ($q) => $q->whereNull('sync_mode')->orWhere('sync_mode', '!=', 'MANUAL'))
                ->count();
            if ($autoActive === 0) {
                return $this->row('biometric_sync', 'Biometric auto-sync', 'warn',
                    "All {$active} active biometric device(s) are set to MANUAL sync, so punches only import "
                    . 'when you press "Sync now". To pull them automatically, open Biometric setup and set each '
                    . 'device to Interval (e.g. every 5 min) or Scheduled.',
                    'kb-biometric-sync');
            }

            // Only INTERVAL/SCHEDULED (automatic) devices are expected to be fresh; a
            // never-synced or >15-min-stale device signals the feed has stalled.
            $stale = (clone $base)->where('status', 'ACTIVE')
                ->where(fn ($q) => $q->whereNull('sync_mode')->orWhere('sync_mode', '!=', 'MANUAL'))
                ->where(fn ($q) => $q->whereNull('last_sync_ok_at')->orWhere('last_sync_ok_at', '<', now()->subMinutes(15)))
                ->count();

            if ($stale > 0) {
                return $this->row('biometric_sync', 'Biometric auto-sync', 'warn',
                    "{$stale} of {$active} biometric device(s) have not completed an automatic sync in the "
                    . 'last 15 minutes. If the scheduler is green above, check the device credentials / '
                    . 'endpoint, or use "Sync now" on the Biometric screen.',
                    'kb-biometric-sync');
            }

            return $this->row('biometric_sync', 'Biometric auto-sync', 'ok',
                "{$active} biometric device(s) synced successfully within the last 15 minutes.");
        } catch (\Throwable $e) {
            return $this->row('biometric_sync', 'Biometric auto-sync', 'warn',
                'Could not check biometric auto-sync status (the database may be unreachable).',
                'kb-db');
        }
    }

    /**
     * Admin #8: screenshots recorded but with NO stored image file (null storage_file_id).
     * This is exactly what makes some violations show "evidence no longer available" — the
     * agent captured a shot but the image never landed in storage (a storage/upload problem
     * on some PCs), as opposed to genuine retention purging.
     */
    private function checkScreenshotEvidence(?int $companyId): array
    {
        try {
            if (! Schema::hasTable('employee_screenshot_logs')) {
                return $this->row('screenshot_evidence', 'Screenshot evidence', 'ok',
                    'No screenshots have been recorded yet.');
            }

            $base = DB::table('employee_screenshot_logs')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->where('captured_at', '>=', now()->subDays(7));

            $total = (clone $base)->count();
            if ($total === 0) {
                return $this->row('screenshot_evidence', 'Screenshot evidence', 'ok',
                    'No screenshots were recorded in the last 7 days.');
            }

            $missing = (clone $base)->whereNull('storage_file_id')->count();
            if ($missing > 0) {
                $pct = (int) round($missing * 100 / max(1, $total));

                return $this->row('screenshot_evidence', 'Screenshot evidence', 'warn',
                    "{$missing} of {$total} screenshots from the last 7 days ({$pct}%) have NO stored image "
                    . 'file. The agent captured them but the image was not saved — usually a storage or upload '
                    . 'problem on some PCs (evidence folder full / not writable, or cloud-storage credentials). '
                    . 'This is why some violations show "evidence no longer available". Check the Evidence '
                    . 'storage folder result above and your cloud-storage settings.',
                    'kb-storage');
            }

            return $this->row('screenshot_evidence', 'Screenshot evidence', 'ok',
                "All {$total} screenshots from the last 7 days have their image stored.");
        } catch (\Throwable $e) {
            return $this->row('screenshot_evidence', 'Screenshot evidence', 'warn',
                'Could not check screenshot evidence (the database may be unreachable).',
                'kb-db');
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function row(string $key, string $label, string $status, string $detail, ?string $fix = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'fix');
    }

    /** Read roughly the last $lines lines of a file without loading it all into memory. */
    private function tail(string $path, int $lines): string
    {
        $size = filesize($path);
        if ($size === 0) {
            return '';
        }

        $fp = fopen($path, 'rb');
        if (! $fp) {
            return '';
        }

        $chunk = 8192;
        $pos = $size;
        $data = '';
        $newlines = 0;

        while ($pos > 0 && $newlines <= $lines) {
            $read = (int) min($chunk, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $buf = fread($fp, $read);
            $data = $buf . $data;
            $newlines = substr_count($data, "\n");
        }
        fclose($fp);

        $all = preg_split('/\r?\n/', rtrim($data, "\r\n"));
        $slice = array_slice($all, -$lines);

        return implode("\n", $slice);
    }

    private function human(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $s) {
            if ($bytes >= $s) {
                return number_format($bytes / $s, 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
