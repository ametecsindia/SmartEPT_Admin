<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 07-Oct-2026 (Ejaz): tracking mode can now be set per DESIGNATION too, like
 * team / department / branch. NULL = inherit. Also the new NO_SCREENSHOTS value
 * (fits the existing string(16) columns, no change needed on the other tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('designations', 'tracking_mode')) {
            Schema::table('designations', fn (Blueprint $t) => $t->string('tracking_mode', 16)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('designations', 'tracking_mode')) {
            Schema::table('designations', fn (Blueprint $t) => $t->dropColumn('tracking_mode'));
        }
    }
};
