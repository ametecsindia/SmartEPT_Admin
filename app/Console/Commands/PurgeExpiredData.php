<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\StorageFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention purge. Deletes tracking data past each company's retention window and removes
 * expired object-store media. Audit + access logs are intentionally excluded so the
 * accountability trail survives. Run nightly via the scheduler.
 */
class PurgeExpiredData extends Command
{
    protected $signature = 'smartept:purge-expired {--dry-run : Report only, delete nothing}';
    protected $description = 'Delete tracking data and media past the per-company retention window';

    /** High-volume tracking tables subject to retention (keyed by their time column). */
    private array $tables = [
        'employee_activity_events'   => 'started_at',
        'employee_idle_logs'         => 'idle_start',
        'employee_break_logs'        => 'start_at',
        'employee_presence_events'   => 'started_at',
        'employee_app_usage_logs'    => 'start_at',
        'employee_website_usage_logs' => 'start_at',
        'employee_network_logs'      => 'changed_at',
        'employee_usb_logs'          => 'occurred_at',
        'employee_screenshot_logs'   => 'captured_at',
        'employee_webcam_logs'       => 'captured_at',
        'employee_compliance_events' => 'started_at',
        'agent_heartbeats'           => 'received_at',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // 1) Expired media files (by storage_files.expires_at) + their bytes.
        $expired = StorageFile::withoutGlobalScopes()->whereNotNull('expires_at')->where('expires_at', '<', now())->get();
        foreach ($expired as $file) {
            $this->line(($dry ? '[dry] ' : '') . "media {$file->storage_key}");
            if (! $dry) {
                try { Storage::disk($file->storage_driver)->delete($file->storage_key); } catch (\Throwable $e) {}
                $file->delete();
            }
        }
        $this->info(($dry ? '[dry] ' : '') . "Expired media: {$expired->count()}");

        // 2) Per-company tracking retention.
        foreach (Company::withoutGlobalScopes()->get() as $company) {
            $days = (int) ($company->data_retention_days ?: config('smartept.default_retention_days', 90));
            $cutoff = now()->subDays($days)->toDateTimeString();

            foreach ($this->tables as $table => $col) {
                if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                    continue;
                }
                $q = DB::table($table)->where('company_id', $company->id)->where($col, '<', $cutoff);
                $n = $dry ? $q->count() : $q->delete();
                if ($n) {
                    $this->line(($dry ? '[dry] ' : '') . "{$company->code} {$table}: {$n} rows > {$days}d");
                }
            }
        }

        $this->info('Retention purge complete.');
        return self::SUCCESS;
    }
}
