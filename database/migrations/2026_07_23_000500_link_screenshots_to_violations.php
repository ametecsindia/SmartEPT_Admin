<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA Phase 5 (B10/B11) — violation ⇄ screenshot evidence linkage + screenshot
 * provenance. The screenshot_logs table already carries `violation_id` (unused
 * until now); we index it and add the correlation id + resolved-policy columns.
 * All additive & reversible; columns guarded, indexes created best-effort so a
 * re-run can't hard-fail on a duplicate index (DBAL-free, works on MySQL + sqlite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_screenshot_logs', function (Blueprint $t) {
            // Correlation id the agent stamps on BOTH the compliance-event and the shot.
            if (! Schema::hasColumn('employee_screenshot_logs', 'client_event_uuid')) {
                $t->string('client_event_uuid', 64)->nullable()->after('violation_id');
            }
            // Which resolved screenshot policy version + interval this shot obeyed (D5 audit).
            if (! Schema::hasColumn('employee_screenshot_logs', 'screenshot_policy_version')) {
                $t->unsignedInteger('screenshot_policy_version')->nullable()->after('screenshot_policy_id');
            }
            if (! Schema::hasColumn('employee_screenshot_logs', 'effective_interval_seconds')) {
                $t->unsignedInteger('effective_interval_seconds')->nullable()->after('screenshot_policy_version');
            }
        });

        Schema::table('employee_compliance_events', function (Blueprint $t) {
            if (! Schema::hasColumn('employee_compliance_events', 'client_event_uuid')) {
                $t->string('client_event_uuid', 64)->nullable()->after('device_uuid');
            }
        });

        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'evidence_window_seconds')) {
                $t->unsignedInteger('evidence_window_seconds')->default(120)
                    ->comment('Correlate a violation screenshot to its event within +/- this many seconds');
            }
        });

        // Indexes — best-effort so a manual re-run can't die on a duplicate.
        $this->tryIndex('employee_screenshot_logs', 'violation_id', 'ept_sslog_violation_idx');
        $this->tryIndex('employee_screenshot_logs', 'client_event_uuid', 'ept_sslog_ceuuid_idx');
        $this->tryIndex('employee_compliance_events', 'client_event_uuid', 'ept_compev_ceuuid_idx');
    }

    public function down(): void
    {
        $this->dropIndexSafe('employee_screenshot_logs', 'ept_sslog_violation_idx');
        $this->dropIndexSafe('employee_screenshot_logs', 'ept_sslog_ceuuid_idx');
        $this->dropIndexSafe('employee_compliance_events', 'ept_compev_ceuuid_idx');

        Schema::table('employee_screenshot_logs', function (Blueprint $t) {
            foreach (['client_event_uuid', 'screenshot_policy_version', 'effective_interval_seconds'] as $c) {
                if (Schema::hasColumn('employee_screenshot_logs', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        Schema::table('employee_compliance_events', function (Blueprint $t) {
            if (Schema::hasColumn('employee_compliance_events', 'client_event_uuid')) {
                $t->dropColumn('client_event_uuid');
            }
        });
        Schema::table('companies', function (Blueprint $t) {
            if (Schema::hasColumn('companies', 'evidence_window_seconds')) {
                $t->dropColumn('evidence_window_seconds');
            }
        });
    }

    private function tryIndex(string $table, string $column, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($column, $name));
        } catch (\Throwable $e) {
            // index already present — fine
        }
    }

    private function dropIndexSafe(string $table, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        } catch (\Throwable $e) {
            // index already gone — fine
        }
    }
};
