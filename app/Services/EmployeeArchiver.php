<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Archive helper (Ejaz 24-Jul). Central registry of every table that holds an
 * employee's data, used both for the compact DB snapshot (counts + date range, kept on
 * the archive row) and for the full row-level export written into the backup ZIP.
 *
 * Every table/column access is guarded with Schema checks so a missing table or a renamed
 * column can never turn a delete into a 500 — the archive is always best-effort complete.
 */
class EmployeeArchiver
{
    /** friendly key => physical table. Everything keyed by employee_id. */
    public function tables(): array
    {
        return [
            'devices'              => 'employee_devices',
            'monitoring_consents'  => 'employee_monitoring_consents',
            'login_sessions'       => 'employee_login_sessions',
            'activity_events'      => 'employee_activity_events',
            'idle_logs'            => 'employee_idle_logs',
            'break_logs'           => 'employee_break_logs',
            'attendance_logs'      => 'employee_attendance_logs',
            'storage_files'        => 'storage_files',
            'screenshots'          => 'employee_screenshot_logs',
            'presence_events'      => 'employee_presence_events',
            'webcam_logs'          => 'employee_webcam_logs',
            'app_usage'            => 'employee_app_usage_logs',
            'website_usage'        => 'employee_website_usage_logs',
            'violations'           => 'employee_compliance_events',
            'daily_summaries'      => 'employee_daily_summaries',
            'status_timeline'      => 'status_timeline',
            'meeting_sessions'     => 'employee_meeting_sessions',
            'meeting_participants' => 'meeting_participants',
            'biometric_mappings'   => 'biometric_employee_mappings',
            'biometric_logs'       => 'biometric_logs',
        ];
    }

    /** Candidate timestamp columns, most specific first, for a best-effort date range. */
    private function dateColumns(): array
    {
        return ['captured_at', 'started_at', 'occurred_at', 'event_time', 'login_at',
            'work_date', 'summary_date', 'created_at'];
    }

    /** Display id for a deleted employee: Code_Name_YYYY-MM-DD. */
    public function label(Employee $employee): string
    {
        $name = trim($employee->first_name . ' ' . (string) $employee->last_name);
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-');

        return $employee->employee_code . '_' . ($slug !== '' ? $slug : 'employee') . '_' . now()->format('Y-m-d');
    }

    /** Only the tables that really exist and carry an employee_id, so callers never guess. */
    public function usableTables(): array
    {
        $out = [];
        foreach ($this->tables() as $key => $table) {
            try {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'employee_id')) {
                    $out[$key] = $table;
                }
            } catch (\Throwable $e) {
                // ignore an unreadable table
            }
        }

        return $out;
    }

    /** Per-table record counts for this employee (compact — stored on the archive row). */
    public function counts(int $employeeId): array
    {
        $out = [];
        foreach ($this->usableTables() as $key => $table) {
            try {
                $out[$key] = (int) DB::table($table)->where('employee_id', $employeeId)->count();
            } catch (\Throwable $e) {
                // skip a table we cannot read
            }
        }

        return $out;
    }

    /** Compact snapshot kept on the archive row: profile + overall data date range. */
    public function snapshot(Employee $employee): array
    {
        $earliest = null;
        $latest = null;
        foreach ($this->usableTables() as $table) {
            $col = $this->firstDateColumn($table);
            if (! $col) {
                continue;
            }
            try {
                $min = DB::table($table)->where('employee_id', $employee->id)->min($col);
                $max = DB::table($table)->where('employee_id', $employee->id)->max($col);
                if ($min && (! $earliest || $min < $earliest)) { $earliest = $min; }
                if ($max && (! $latest || $max > $latest)) { $latest = $max; }
            } catch (\Throwable $e) {
                // skip
            }
        }

        return [
            'profile'        => $employee->attributesToArray(),
            'full_name'      => $employee->fullName(),
            'data_from'      => $earliest ? (string) $earliest : null,
            'data_to'        => $latest ? (string) $latest : null,
            'snapshot_taken' => now()->toDateTimeString(),
        ];
    }

    public function firstDateColumn(string $table): ?string
    {
        foreach ($this->dateColumns() as $col) {
            try {
                if (Schema::hasColumn($table, $col)) {
                    return $col;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return null;
    }
}
