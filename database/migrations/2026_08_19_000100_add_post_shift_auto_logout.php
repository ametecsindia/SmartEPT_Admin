<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-shift auto logout (Ejaz, 19-Aug-2026).
 *
 * When an agent never signs out, the session stayed open and `check_out_at` kept whatever
 * stale instant last happened to be written — which is exactly what produced the 596%
 * productivity row for AI0043 on 14-Aug. The application must close the agent out itself,
 * a configurable number of minutes AFTER shift end.
 *
 * Two places hold the value, deliberately:
 *   shifts.post_shift_auto_logout_minutes             — per shift, wins when set
 *   attendance_policies.post_shift_auto_logout_minutes — the fallback default
 * NULL in both = feature off for that employee (no behaviour change on upgrade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedInteger('post_shift_auto_logout_minutes')->nullable()->after('break_minutes_allowed');
        });

        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->unsignedInteger('post_shift_auto_logout_minutes')->nullable()->after('min_working_hours');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('post_shift_auto_logout_minutes');
        });

        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->dropColumn('post_shift_auto_logout_minutes');
        });
    }
};
