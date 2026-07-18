<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biometric Gate (Doc 11 v1.1, Ejaz 16-Jul): per-company gate mode.
 *  auto (default) — gate is ON when the company has an ACTIVE biometric device,
 *                   OFF when it has none ("with biometric" follows the device setup).
 *  on / off       — explicit override (pilot/observe or force).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('biometric_gate', 8)->default('auto')->after('data_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('biometric_gate');
        });
    }
};
