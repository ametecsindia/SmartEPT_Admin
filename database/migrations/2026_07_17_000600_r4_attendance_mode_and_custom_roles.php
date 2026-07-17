<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R4 (Ejaz 17-Jul evening list):
 * - item 3: per-company attendance source — with / without a biometric device.
 * - item 5: custom organisation roles; base_slug = the system role a custom role
 *   inherits route access from (the permission matrix narrows modules further).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('attendance_mode', 20)->default('BIOMETRIC')->after('data_retention_days');
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->string('base_slug', 64)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('attendance_mode'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('base_slug'));
    }
};
