<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 8 — automatic biometric sync modes + richer status. A device can sync on a
 * fixed INTERVAL, at SCHEDULED daily times, or MANUAL only. Status columns expose the
 * last successful/attempted sync, the next scheduled run, and the last counts so the
 * admin never has to open the tab and click Sync to know what happened.
 *
 * Backfill: any ACTIVE device that already had automatic sync ticked (sync_enabled)
 * becomes INTERVAL; everything else defaults to INTERVAL too (auto-on for a configured
 * integration, per the spec) — the console lets an admin switch a device to MANUAL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->enum('sync_mode', ['INTERVAL', 'SCHEDULED', 'MANUAL'])->default('INTERVAL')->after('sync_enabled');
            $table->unsignedSmallInteger('sync_interval_minutes')->default(5)->after('sync_mode');
            $table->json('sync_times')->nullable()->after('sync_interval_minutes'); // ["09:00","13:30"] local time
            $table->timestamp('last_sync_ok_at')->nullable()->after('last_sync_at');
            $table->timestamp('next_sync_at')->nullable()->after('last_sync_ok_at');
            $table->json('last_sync_counts')->nullable()->after('last_sync_result'); // {fetched,stored,duplicate,unmapped}
        });

        // Keep automatic devices automatic; leave the rest on the INTERVAL default so a
        // freshly-configured integration syncs without anyone toggling anything.
        // (MANUAL is an explicit opt-out chosen in the console.)
    }

    public function down(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropColumn([
                'sync_mode', 'sync_interval_minutes', 'sync_times',
                'last_sync_ok_at', 'next_sync_at', 'last_sync_counts',
            ]);
        });
    }
};
