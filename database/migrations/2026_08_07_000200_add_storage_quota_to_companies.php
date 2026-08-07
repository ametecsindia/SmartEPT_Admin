<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client hard storage quota (Ejaz, 7-Aug-2026). MB cap on a company's stored
 * evidence; null / 0 = unlimited. Enforced in StorageService (auto-trim oldest
 * when full). Raising this number is the "buy more space" action (Super Admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_quota_mb')->nullable()->after('data_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('storage_quota_mb');
        });
    }
};
