<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R2-4: nightly database backup with zero external dependencies.
 *
 * Pure-PHP dump (works on MySQL and SQLite alike — no mysqldump.exe hunt on
 * Laragon/Windows): CREATE-agnostic INSERT script per table, gzipped into
 * storage/app/backups/. Keeps the newest N files (default 14).
 *
 * Restore: create an empty schema via `php artisan migrate:fresh`, then run
 * the SQL through your client (the file contains plain INSERTs).
 */
class BackupDatabase extends Command
{
    protected $signature = 'smartept:backup-database {--keep=14}';

    protected $description = 'Write a gzipped data backup of the database into storage/app/backups.';

    public function handle(): int
    {
        $dir = storage_path('app/backups');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir . '/smartept-backup-' . now()->format('Y-m-d-His') . '.sql.gz';
        $gz = gzopen($file, 'wb6');

        gzwrite($gz, "-- SmartEPT data backup " . now()->toDateTimeString() . "\n");
        gzwrite($gz, "-- Restore into a schema created by `php artisan migrate:fresh`.\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");

        $tables = $this->tableNames();
        $rowsTotal = 0;

        foreach ($tables as $table) {
            if (in_array($table, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'], true)) {
                continue;
            }

            gzwrite($gz, "\n-- {$table}\nDELETE FROM `{$table}`;\n");

            DB::table($table)->orderBy($this->firstColumn($table))->chunk(500, function ($rows) use ($gz, $table, &$rowsTotal) {
                foreach ($rows as $row) {
                    $vals = array_map(function ($v) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        if (is_int($v) || is_float($v)) {
                            return (string) $v;
                        }

                        return DB::getPdo()->quote((string) $v);
                    }, (array) $row);

                    $cols = '`' . implode('`,`', array_keys((array) $row)) . '`';
                    gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(',', $vals) . ");\n");
                    $rowsTotal++;
                }
            });
        }

        gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        $this->info('Backup written: ' . basename($file) . ' (' . number_format(filesize($file) / 1024, 1) . ' KB, ' . $rowsTotal . ' rows, ' . count($tables) . ' tables).');

        // Retention: keep the newest N backups.
        $keep = max(1, (int) $this->option('keep'));
        $files = glob($dir . '/smartept-backup-*.sql.gz');
        rsort($files);

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('Pruned old backup: ' . basename($old));
        }

        return self::SUCCESS;
    }

    private function tableNames(): array
    {
        return array_map(fn ($t) => $t['name'], Schema::getTables());
    }

    private function firstColumn(string $table): string
    {
        $cols = Schema::getColumnListing($table);

        return in_array('id', $cols, true) ? 'id' : $cols[0];
    }
}
