<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Biometric Bridge (SBB) hardening — 16-Aug-2026.
 *
 * SBB is at-least-once: the same punch may be delivered more than once, so the
 * public ingest endpoint needs a per-punch identity it can reject duplicates on.
 *
 * NOTE — why external_id and NOT raw_log_ref:
 * raw_log_ref looks free, but BiometricCloudSync already writes a NON-unique
 * machine tag there ("MC:1" / "MC:2" for the IN and OUT readers — see
 * BiometricCloudSync::sync). A unique(company_id, raw_log_ref) index would
 * therefore reject every punch after the first on the eTimeOffice cloud path.
 * external_id is a dedicated column for the bridge's deterministic SHA-256
 * fingerprint; raw_log_ref keeps its existing "which reader" meaning.
 *
 * metadata carries what the punch_type enum cannot express: the caller's own
 * source string (device serial), direction_confidence (a face terminal that
 * sends status 255 does not know whether the punch was IN or OUT) and the raw
 * device status code.
 *
 * api_keys gains expires_at + allowed_ips: SBB stores its key in a config file
 * on a customer's Windows PC, and a leaked key was previously valid forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_logs', function (Blueprint $t) {
            $t->string('external_id', 96)->nullable()->after('raw_log_ref');
            $t->json('metadata')->nullable()->after('external_id');
            $t->unique(['company_id', 'external_id'], 'biometric_logs_company_external_unique');
        });

        Schema::table('api_keys', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable()->after('last_used_at');
            $t->json('allowed_ips')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_logs', function (Blueprint $t) {
            $t->dropUnique('biometric_logs_company_external_unique');
            $t->dropColumn(['external_id', 'metadata']);
        });

        Schema::table('api_keys', function (Blueprint $t) {
            $t->dropColumn(['expires_at', 'allowed_ips']);
        });
    }
};
