<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LiveView multi-screen (14-Sep-2026): the admin's "Desktop 1 / Desktop 2 / ..." picker
 * needs to know how many monitors an employee's PC actually has, so it stops offering a
 * fixed "Primary" option. The Agent already enumerates displays for capture itself
 * (screen.getAllDisplays() in liveview/index.js) — it just now reports the count on every
 * heartbeat, same piggyback pattern as app_version/sync_pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('monitor_count')->default(1)->after('microphone_available');
        });
    }

    public function down(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->dropColumn('monitor_count');
        });
    }
};
