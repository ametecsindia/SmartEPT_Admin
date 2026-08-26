<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Outbound webhook secrets could not be saved (26-Aug-2026, live, SmartPRS
 * integration): "SQLSTATE[22001] Data too long for column 'secret'".
 *
 * The column was created as string() = VARCHAR(255), but IntegrationTarget
 * casts it 'encrypted' — Laravel stores a base64 JSON envelope (iv + value +
 * mac), never the plaintext. That envelope is ~4-5x the secret, so the real
 * ceiling was only ~31 characters:
 *
 *   plaintext <=31 -> 228 stored (fits)   32-47 -> 256 (1 over!)   48-63 -> 288
 *
 * Anything longer died on insert. Note Str::random(32) — what saveTarget()
 * auto-generates when the admin leaves the field blank — lands on 256 and
 * failed too, so a blank secret was equally unusable.
 *
 * Fix at the source: TEXT, which is what every encrypted column should be.
 * The validator's max:200 then becomes the only limit, as intended.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite (tests) ignores VARCHAR lengths — nothing to widen
        }

        // ponytail: raw ALTER (no doctrine/dbal); MySQL/MariaDB only, which is what SmartEPT runs.
        DB::statement('ALTER TABLE `integration_targets` MODIFY `secret` TEXT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Best-effort revert. Any secret longer than ~31 characters was only
        // storable because of this migration, so MySQL would truncate it on the
        // way back — acceptable for rolling back a widening change.
        DB::statement('ALTER TABLE `integration_targets` MODIFY `secret` VARCHAR(255) NULL');
    }
};
