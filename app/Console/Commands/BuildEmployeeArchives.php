<?php

namespace App\Console\Commands;

use App\Models\EmployeeArchive;
use App\Services\EmployeeArchiver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Employee Archive builder (Ejaz 24-Jul). When an employee is deleted, destroy() creates an
 * EmployeeArchive row (PENDING) and frees the code immediately. This command — run every
 * minute by the scheduler — does the heavy lifting: it writes a ZIP with the full row-level
 * export (one NDJSON file per data table, streamed in chunks so memory stays flat even for a
 * heavy employee) plus the ACTUAL screenshot/webcam image files, then flips the row to READY.
 * Kept out of the web request so a delete is always instant, and needs no queue worker.
 */
class BuildEmployeeArchives extends Command
{
    protected $signature = 'smartept:build-archives {--limit=3 : Max archives to build this run} {--id= : Build one archive id now}';

    protected $description = 'Build the backup ZIP (data + media) for deleted-employee archives';

    /** Rows fetched per DB page while streaming a table to NDJSON. */
    private const CHUNK = 2000;

    public function handle(EmployeeArchiver $archiver): int
    {
        $lock = Cache::lock('smartept:archive-builder', 290);
        if (! $lock->get()) {
            $this->info('Another archive build is already running.');

            return self::SUCCESS;
        }

        try {
            if ($this->option('id')) {
                $pending = EmployeeArchive::withoutGlobalScopes()
                    ->whereKey((int) $this->option('id'))->get();
            } else {
                $pending = EmployeeArchive::withoutGlobalScopes()
                    ->where('file_status', 'PENDING')
                    ->orderBy('id')
                    ->limit(max(1, (int) $this->option('limit')))
                    ->get();
            }

            if ($pending->isEmpty()) {
                $this->info('No archives pending.');

                return self::SUCCESS;
            }

            foreach ($pending as $archive) {
                $this->buildOne($archive, $archiver);
            }

            return self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }

    private function buildOne(EmployeeArchive $archive, EmployeeArchiver $archiver): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $archive->forceFill(['file_status' => 'FAILED',
                'error' => 'The PHP zip extension is not installed on the server.'])->save();
            $this->error("archive {$archive->id}: PHP zip extension missing.");

            return;
        }

        $tmpDir = storage_path('app/tmp/archive_' . $archive->id);
        try {
            $employeeId = $archive->employee_id;
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $archive->archive_label);
            $key  = 'archives/' . $archive->company_id . '/' . $archive->id . '_' . $safe . '.zip';
            // Write through the same disk the download reads from. Laravel 11's 'local'
            // disk root is storage/app/private, so storage_path('app/...') would land the
            // file where the download can't find it (EPT25-02).
            $abs  = \Illuminate\Support\Facades\Storage::disk('local')->path($key);
            @mkdir(dirname($abs), 0775, true);
            @mkdir($tmpDir, 0775, true);

            $zip = new \ZipArchive();
            if ($zip->open($abs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the ZIP file at ' . $key);
            }

            // 1) manifest
            $zip->addFromString('employee.json', json_encode([
                'archive' => [
                    'label'         => $archive->archive_label,
                    'employee_code' => $archive->original_employee_code,
                    'employee_name' => $archive->employee_name,
                    'archived_at'   => (string) $archive->archived_at,
                ],
                'profile' => $archive->snapshot['profile'] ?? null,
                'counts'  => $archive->counts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $zip->addFromString('README.txt',
                "SmartEPT — Employee data archive\n"
                . "================================\n"
                . 'Employee : ' . $archive->employee_name . " ({$archive->original_employee_code})\n"
                . 'Archived : ' . $archive->archived_at . "\n\n"
                . "employee.json   — profile + a record count for every data type.\n"
                . "data/*.ndjson   — the full record-by-record export (one JSON object per line).\n"
                . "screenshots/    — the actual screenshot images captured for this person.\n"
                . "webcam/         — webcam presence photos (if any).\n");

            // 2) full row-level export — one NDJSON file per table, streamed in chunks so
            //    memory stays flat regardless of how much data the employee accumulated.
            foreach ($archiver->usableTables() as $tkey => $table) {
                $file = $tmpDir . '/' . $tkey . '.ndjson';
                $rows = $this->streamTable($table, $employeeId, $file);
                if ($rows > 0) {
                    $zip->addFile($file, 'data/' . $tkey . '.ndjson');
                }
            }

            // 3) actual media files (screenshots + webcam), streamed from storage
            $mediaCount = 0;
            if (Schema::hasTable('storage_files') && Schema::hasColumn('storage_files', 'employee_id')) {
                DB::table('storage_files')->where('employee_id', $employeeId)
                    ->orderBy('id')->chunk(self::CHUNK, function ($files) use ($zip, &$mediaCount) {
                        foreach ($files as $f) {
                            $mediaCount += $this->addMedia($zip, $f) ? 1 : 0;
                        }
                    });
            }

            $zip->close();

            $size = @filesize($abs) ?: 0;
            $archive->forceFill([
                'file_status'    => 'READY',
                'storage_driver' => 'local',
                'storage_key'    => $key,
                'file_size'      => $size,
                'media_files'    => $mediaCount,
                'error'          => null,
            ])->save();

            $this->info("archive {$archive->id}: READY ({$mediaCount} media files, "
                . number_format($size / 1048576, 1) . ' MB)');
        } catch (\Throwable $e) {
            $archive->forceFill(['file_status' => 'FAILED',
                'error' => mb_substr($e->getMessage(), 0, 990)])->save();
            $this->error("archive {$archive->id}: FAILED — " . $e->getMessage());
        } finally {
            $this->rmdir($tmpDir);
        }
    }

    /** Stream one table's rows for this employee to an NDJSON file; returns row count. */
    private function streamTable(string $table, ?int $employeeId, string $file): int
    {
        $written = 0;
        $fh = @fopen($file, 'w');
        if (! $fh) {
            return 0;
        }
        try {
            DB::table($table)->where('employee_id', $employeeId)->orderBy('id')
                ->chunk(self::CHUNK, function ($rows) use ($fh, &$written) {
                    foreach ($rows as $row) {
                        fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                        $written++;
                    }
                });
        } catch (\Throwable $e) {
            fwrite($fh, json_encode(['_error' => 'could not read table: ' . $e->getMessage()]) . "\n");
        } finally {
            fclose($fh);
        }

        if ($written === 0) {
            @unlink($file);
        }

        return $written;
    }

    /** Add one stored media file to the ZIP; returns true if it was added. */
    private function addMedia(\ZipArchive $zip, object $f): bool
    {
        $driver = $f->storage_driver ?? null;
        $skey   = $f->storage_key ?? null;
        if (! $driver || ! $skey) {
            return false;
        }

        try {
            $disk = Storage::disk($driver);
            if (! $disk->exists($skey)) {
                return false;
            }

            $type   = strtoupper((string) ($f->file_type ?? ''));
            $folder = str_contains($type, 'WEBCAM') ? 'webcam'
                : (str_contains($type, 'SCREEN') ? 'screenshots' : 'files');
            $ext = pathinfo($skey, PATHINFO_EXTENSION) ?: 'bin';
            $entry = $folder . '/' . $f->id . '.' . $ext;

            // Local disks: add by path (no PHP memory). Cloud disks: pull bytes.
            if (in_array($driver, ['local', 'public'], true)) {
                $zip->addFile($disk->path($skey), $entry);
            } else {
                $zip->addFromString($entry, $disk->get($skey));
            }

            return true;
        } catch (\Throwable $e) {
            return false; // one unreadable file never fails the whole archive
        }
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) @scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($dir . '/' . $f);
            }
        }
        @rmdir($dir);
    }
}
