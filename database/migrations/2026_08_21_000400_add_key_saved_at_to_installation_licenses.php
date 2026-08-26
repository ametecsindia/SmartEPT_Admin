<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The anchor for the unverified-key window.
 *
 * A key that Central has never confirmed is usable for 7 days and then blocks.
 * That window was measured from `last_checked_at` — and the unreachable branch
 * of LicenseClient::validate() writes `last_checked_at = now()` on EVERY failed
 * attempt. On an air-gapped server the nightly phone-home failed, bumped the
 * anchor, and reopened the window. Every night. For ever.
 *
 * So on exactly the deployment the guard was written for — offline, on-premise —
 * typing any string at all into the Licence screen bought permanent, uncapped
 * access. The regression test passed because it never runs a phone-home between
 * its two assertions.
 *
 * `key_saved_at` is written only where a key is actually entered. Nothing a
 * validation attempt does can move it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('installation_licenses')) {
            return;
        }

        if (! Schema::hasColumn('installation_licenses', 'key_saved_at')) {
            Schema::table('installation_licenses', function (Blueprint $t) {
                $t->timestamp('key_saved_at')->nullable()->after('license_key');
            });
        }

        // Backfill. created_at is the honest choice for an existing row: it is
        // the only timestamp already on the table that a phone-home never moves.
        //
        // This is deliberately strict. An install that has been sitting on an
        // unconfirmed key for months will block on the next request — which is
        // the correct outcome and the entire point of the fix. A genuinely paid
        // client is unaffected: a signed .lic makes the status 'active', and an
        // active licence never consults this window at all.
        DB::table('installation_licenses')
            ->whereNull('key_saved_at')
            ->whereNotNull('license_key')
            ->update(['key_saved_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (Schema::hasTable('installation_licenses') && Schema::hasColumn('installation_licenses', 'key_saved_at')) {
            Schema::table('installation_licenses', function (Blueprint $t) {
                $t->dropColumn('key_saved_at');
            });
        }
    }
};
