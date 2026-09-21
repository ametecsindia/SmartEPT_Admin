<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LiveView licensing switched (21-Sep-2026, Ejaz) from a simultaneous-viewing
 * cap to a permission-based cap: the licence now limits how many employees may
 * be GRANTED LiveView permission, not how many screens may be open at once.
 * This is the per-employee grant itself. An employee without it is never
 * offered for LiveView at all — see LiveViewController::start()/setPermission().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'liveview_enabled')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->boolean('liveview_enabled')->default(false)->after('enforcement_exempt_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'liveview_enabled')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->dropColumn('liveview_enabled');
            });
        }
    }
};
