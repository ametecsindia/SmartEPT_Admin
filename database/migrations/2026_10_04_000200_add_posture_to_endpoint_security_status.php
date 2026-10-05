<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 04-Oct-2026 — bank checklist items the PC now reports with every security sync:
 * Windows version/build, last patch, BitLocker, local administrators, screen-lock policy,
 * Office macro policy. One nullable JSON column on our own table; additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('endpoint_security_status', 'posture')) {
            Schema::table('endpoint_security_status', fn (Blueprint $t) => $t->json('posture')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('endpoint_security_status', 'posture')) {
            Schema::table('endpoint_security_status', fn (Blueprint $t) => $t->dropColumn('posture'));
        }
    }
};
