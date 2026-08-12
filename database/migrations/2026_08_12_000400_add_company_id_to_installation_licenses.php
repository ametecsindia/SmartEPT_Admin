<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant licensing on the shared cloud install (Ejaz, 12-Aug-2026).
 *
 * installation_licenses grows a nullable company_id:
 *   NULL  → the install-level licence (client-hosted servers — unchanged),
 *   set   → that cloud tenant company's OWN licence row (AMETECS_SAAS).
 *
 * Data move: the live install slot was holding ONE cloud tenant's key (Khan) —
 * the only place it could go before this table knew about companies. If the
 * install row's cached bundle names exactly one provisioned AMETECS_SAAS
 * company, the row becomes that tenant's licence and the install slot starts
 * clean. No match → nothing moves (safe on client-hosted installs, where the
 * bundle names the client's own company but no AMETECS_SAAS row exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_licenses', function (Blueprint $table) {
            // Unique: one licence row per tenant. MySQL allows many NULLs, so
            // the install-level row coexists. VARCHAR/BIGINT only — no ENUMs.
            $table->unsignedBigInteger('company_id')->nullable()->unique()->after('id');
        });

        $row = DB::table('installation_licenses')
            ->whereNull('company_id')
            ->whereNotNull('license_key')
            ->orderBy('id')
            ->first();

        if (! $row) {
            return;
        }

        $bundle = json_decode($row->bundle ?? 'null', true);
        $name = is_array($bundle) ? ($bundle['company'] ?? null) : null;

        if (! $name) {
            return;
        }

        $matches = DB::table('companies')
            ->where('deployment_model', 'AMETECS_SAAS')
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($matches->count() === 1) {
            DB::table('installation_licenses')
                ->where('id', $row->id)
                ->update(['company_id' => $matches->first(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('installation_licenses', function (Blueprint $table) {
            $table->dropUnique(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
