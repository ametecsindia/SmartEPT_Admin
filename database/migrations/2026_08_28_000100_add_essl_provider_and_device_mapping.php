<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second biometric provider — eSSL eTimeTrackLite Web API (Ejaz, 28-Aug-2026).
 *
 * Everything here is ADDITIVE and defaults to today's behaviour, so every existing
 * eTimeOffice device keeps working byte-for-byte:
 *
 *  - provider_key         canonical provider selector. `provider` stays as the free-text
 *                         display name it has always been (the console shows it in the
 *                         device list), so nothing that reads it breaks. Existing rows
 *                         backfill to ETIMEOFFICE.
 *  - floor                floor / location WITHIN a branch. `location` already existed and
 *                         keeps its meaning; a real company is Company → Branch → Floor →
 *                         Device, and the branch link (branch_id) was already there.
 *  - punch_direction_mode how THIS reader decides IN vs OUT:
 *                           AUTO     — the existing rule: IN/OUT machine IDs override the
 *                                      feed flag, feed flag next, otherwise alternate.
 *                                      This is the default, so no existing device changes.
 *                           IN_ONLY  — entry reader, every punch is IN.
 *                           OUT_ONLY — exit reader, every punch is OUT.
 *                           IN_OUT   — one reader used for both: the employee's punch
 *                                      sequence for that day decides (1st IN, 2nd OUT,
 *                                      3rd IN …). See PunchDirectionResolver.
 *
 * varchar, not enum: this project has twice had to widen an enum in production
 * (action_on_blocked, usage category). A new mode must never need a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('biometric_devices', 'provider_key')) {
                $table->string('provider_key', 40)->default('ETIMEOFFICE')->after('provider');
            }
            if (! Schema::hasColumn('biometric_devices', 'floor')) {
                $table->string('floor', 120)->nullable()->after('location');
            }
            if (! Schema::hasColumn('biometric_devices', 'punch_direction_mode')) {
                $table->string('punch_direction_mode', 20)->default('AUTO')->after('out_machine_id');
            }
        });

        // Belt and braces: some sqlite builds do not backfill a defaulted column on
        // existing rows, and a NULL provider_key would fall through to the registry's
        // "guess from the provider text" path instead of being explicit.
        DB::table('biometric_devices')->whereNull('provider_key')->update(['provider_key' => 'ETIMEOFFICE']);
        DB::table('biometric_devices')->whereNull('punch_direction_mode')->update(['punch_direction_mode' => 'AUTO']);
    }

    public function down(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropColumn(['provider_key', 'floor', 'punch_direction_mode']);
        });
    }
};
