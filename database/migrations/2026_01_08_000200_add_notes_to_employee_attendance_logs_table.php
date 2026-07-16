<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance rows can now be created/edited by HR (auto-absent marking + manual
 * regularization). `notes` carries the human audit trail: why a row exists or
 * was changed, stamped with time + actor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_attendance_logs', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendance_logs', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
