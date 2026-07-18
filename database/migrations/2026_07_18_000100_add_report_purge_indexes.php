<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R5 EPT-13/14: performance indexes for the nightly retention purge and the
 * date-range dashboard/report queries. The tracking tables already carry a
 * (company_id, employee_id, <ts>) index, but the middle employee_id prevents an
 * efficient range scan on <ts> for company-wide "WHERE company_id = ? AND <ts> <
 * cutoff" deletes/reads. These add a leaner (company_id, <ts>) index alongside.
 * EPT-14: also index the previously-loose screenshot policy/violation FKs.
 *
 * NOTE: the duplicate 2026_07_17_000500 migration prefix was intentionally NOT
 * renamed - both files are already recorded in the migrations table on live DBs,
 * and renaming would make them re-run as "pending" and corrupt migration state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_activity_events', fn (Blueprint $t) => $t->index(['company_id', 'started_at'], 'ept_actev_co_started_idx'));
        Schema::table('employee_idle_logs', fn (Blueprint $t) => $t->index(['company_id', 'idle_start'], 'ept_idle_co_start_idx'));
        Schema::table('employee_break_logs', fn (Blueprint $t) => $t->index(['company_id', 'start_at'], 'ept_break_co_start_idx'));
        Schema::table('employee_presence_events', fn (Blueprint $t) => $t->index(['company_id', 'started_at'], 'ept_pres_co_started_idx'));
        Schema::table('employee_webcam_logs', fn (Blueprint $t) => $t->index(['company_id', 'captured_at'], 'ept_wcam_co_captured_idx'));
        Schema::table('employee_app_usage_logs', fn (Blueprint $t) => $t->index(['company_id', 'start_at'], 'ept_appu_co_start_idx'));
        Schema::table('employee_website_usage_logs', fn (Blueprint $t) => $t->index(['company_id', 'start_at'], 'ept_webu_co_start_idx'));
        Schema::table('employee_compliance_events', fn (Blueprint $t) => $t->index(['company_id', 'started_at'], 'ept_compev_co_started_idx'));

        Schema::table('employee_screenshot_logs', function (Blueprint $t) {
            $t->index(['company_id', 'captured_at'], 'ept_sslog_co_captured_idx');
            $t->index('screenshot_policy_id', 'ept_sslog_policy_idx');
            $t->index('violation_id', 'ept_sslog_violation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employee_activity_events', fn (Blueprint $t) => $t->dropIndex('ept_actev_co_started_idx'));
        Schema::table('employee_idle_logs', fn (Blueprint $t) => $t->dropIndex('ept_idle_co_start_idx'));
        Schema::table('employee_break_logs', fn (Blueprint $t) => $t->dropIndex('ept_break_co_start_idx'));
        Schema::table('employee_presence_events', fn (Blueprint $t) => $t->dropIndex('ept_pres_co_started_idx'));
        Schema::table('employee_webcam_logs', fn (Blueprint $t) => $t->dropIndex('ept_wcam_co_captured_idx'));
        Schema::table('employee_app_usage_logs', fn (Blueprint $t) => $t->dropIndex('ept_appu_co_start_idx'));
        Schema::table('employee_website_usage_logs', fn (Blueprint $t) => $t->dropIndex('ept_webu_co_start_idx'));
        Schema::table('employee_compliance_events', fn (Blueprint $t) => $t->dropIndex('ept_compev_co_started_idx'));

        Schema::table('employee_screenshot_logs', function (Blueprint $t) {
            $t->dropIndex('ept_sslog_co_captured_idx');
            $t->dropIndex('ept_sslog_policy_idx');
            $t->dropIndex('ept_sslog_violation_idx');
        });
    }
};
