<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EmployeeScreenshotLog;
use App\Models\StorageFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Why does every screenshot say "file missing"?
 *
 * The console prints that phrase for ANY non-403 error from
 * GET /api/screenshots/{id}/file, and that endpoint fails for four different
 * reasons that look identical on screen:
 *
 *   1. the employee_screenshot_logs row has no storage_file_id at all;
 *   2. the storage_files row was deleted (retention purge, or the quota trim);
 *   3. the row is there and the OBJECT is not on the disk it names;
 *   4. the disk it names cannot be opened at all (a bucket that is configured
 *      but not registered, a credential that no longer decrypts).
 *
 * Guessing between those cost a whole debugging round once. This asks the disk.
 * Read-only: it opens files, it never writes or deletes.
 */
class WhyEvidenceMissing extends Command
{
    protected $signature = 'smartept:why-evidence-missing
        {--company= : Company id (default: every company)}
        {--sample=20 : How many recent screenshots to actually open}';

    protected $description = 'Explain why stored screenshots/webcam evidence cannot be shown';

    public function handle(): int
    {
        $companies = $this->option('company')
            ? Company::withoutGlobalScopes()->whereKey((int) $this->option('company'))->get()
            : Company::withoutGlobalScopes()->get();

        if ($companies->isEmpty()) {
            $this->error('No such company.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  default disk for NEW uploads: ' . app(\App\Services\StorageService::class)->disk());
        $this->line('  gcs_enabled setting:          ' . (\App\Models\Setting::get('gcs_enabled') ?: '(unset)'));
        $this->line('  gcs disk registered:          ' . (array_key_exists('gcs', config('filesystems.disks', [])) ? 'yes' : 'NO'));

        foreach ($companies as $company) {
            $this->line('');
            $this->line('  ══ ' . $company->code . ' (id ' . $company->id . ') ' . str_repeat('═', 40));

            // 1. Quota. Null or 0 means the trimmer never runs at all.
            $quota = (int) ($company->storage_quota_mb ?: 0);
            $usedB = (int) StorageFile::withoutGlobalScopes()->where('company_id', $company->id)->sum('size_bytes');
            $this->line(sprintf('  quota        %s   used %d MB',
                $quota > 0 ? $quota . ' MB' : 'unlimited (trimmer never runs)',
                (int) round($usedB / 1048576)));

            // 2. Screenshot rows whose storage_files row is gone. That is a
            //    DELETE somebody did — retention, the old quota trim, or by hand.
            $shots = EmployeeScreenshotLog::withoutGlobalScopes()->where('company_id', $company->id);
            $total = (clone $shots)->count();
            $noRef = (clone $shots)->whereNull('storage_file_id')->count();
            $orphan = (clone $shots)->whereNotNull('storage_file_id')
                ->whereNotIn('storage_file_id', StorageFile::withoutGlobalScopes()->select('id'))
                ->count();

            $this->line(sprintf('  screenshots  %d rows   %d never stored   %d point at a deleted storage_files row',
                $total, $noRef, $orphan));

            // 3. The only question that matters: is the object actually there?
            //    Ask the disk for the most recent ones rather than believing the DB.
            $recent = StorageFile::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderByDesc('id')
                ->limit(max(1, (int) $this->option('sample')))
                ->get();

            $present = 0;
            $missing = [];
            $unreadable = [];

            foreach ($recent as $file) {
                try {
                    if (Storage::disk($file->storage_driver)->exists($file->storage_key)) {
                        $present++;
                    } else {
                        $missing[] = $file;
                    }
                } catch (\Throwable $e) {
                    // The disk itself will not open. Every file on it is unreadable,
                    // however healthy the database looks.
                    $unreadable[$file->storage_driver] = $e->getMessage();
                }
            }

            $this->line(sprintf('  checked      %d newest file(s): %d present, %d missing on disk, %d unreadable disk',
                $recent->count(), $present, count($missing), count($unreadable)));

            foreach ($unreadable as $driver => $msg) {
                $this->error('  FAIL  disk [' . $driver . '] cannot be opened: ' . $msg);
                $this->line('        Every file recorded against this disk will read as "file missing".');
            }

            foreach (array_slice($missing, 0, 5) as $file) {
                $this->line('        gone: [' . $file->storage_driver . '] ' . $file->storage_key
                    . '  (' . $file->created_at . ')');
            }

            // 4. The verdict, in one line, naming what to do next.
            if ($recent->isEmpty()) {
                $this->warn('  Nothing is stored for this company at all — look at uploads, not at storage.');
            } elseif ($unreadable) {
                $this->error('  VERDICT  the disk is the problem, not the data. Fix the disk/bucket config.');
            } elseif (count($missing) === $recent->count()) {
                $this->error('  VERDICT  the rows are fine and every object is gone. Something deleted the files'
                    . ' underneath the app — a redeploy that replaced storage/, or a cleanup outside SmartEPT.');
            } elseif ($orphan > 0) {
                $this->error('  VERDICT  ' . $orphan . ' screenshots point at deleted storage_files rows —'
                    . ' evidence was purged. Check retention days and the storage quota.');
            } elseif ($noRef === $total && $total > 0) {
                $this->error('  VERDICT  no screenshot ever reached storage. An upload problem, not a storage one.');
            } elseif ($present === $recent->count()) {
                $this->info('  VERDICT  storage is healthy. If the console still says "file missing", open one'
                    . ' tile with the browser Network tab and read the 404 body.');
            } else {
                $this->warn('  VERDICT  mixed — some objects are gone. See the list above for when they stop.');
            }
        }

        $this->line('');

        return self::SUCCESS;
    }
}
