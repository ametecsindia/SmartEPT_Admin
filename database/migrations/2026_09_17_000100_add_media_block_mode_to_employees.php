<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee media-streaming block override.
 *
 * Same tri-state shape as enforcement_mode/tracking_mode: null means "inherit
 * the company's block_media_streaming switch", 'BLOCKED' forces the local
 * media-control proxy on for this employee even if the company switch is
 * off, 'ALLOWED' forces it off for this employee even if the company switch
 * is on. See EnforcerSyncController::policy() (the EMPLOYEE-scope spec) and
 * smartept-enforcer/internal/store/store.go's MediaControl.Enabled doc
 * comment for how the override rides the employee overlay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'media_block_mode')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->string('media_block_mode', 16)->nullable()->after('enforcement_exempt_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'media_block_mode')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->dropColumn('media_block_mode');
            });
        }
    }
};
