<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 07-Oct-2026 (Ejaz): Schedule Report → "Reporting team's productivity". Every reporting manager
 * (employees.reporting_manager_user_id) gets only their reporting employees; Company / HR /
 * Branch Admins get the whole company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $t) {
            if (! Schema::hasColumn('report_schedules', 'team_reports')) {
                $t->boolean('team_reports')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $t) {
            if (Schema::hasColumn('report_schedules', 'team_reports')) {
                $t->dropColumn('team_reports');
            }
        });
    }
};
