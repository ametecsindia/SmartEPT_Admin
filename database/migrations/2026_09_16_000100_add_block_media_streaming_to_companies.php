<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level switch: force-install the SmartEPT Media Control browser
 * extension and have it block video playback in every browser on every
 * enrolled PC, DOM-level, regardless of site or CDN. Same shape as
 * block_browser_uploads / block_camera_device: machine-wide, admin-controlled,
 * default off. See EnforcerSyncController::mediaControlFor() and
 * smartept-enforcer/internal/apply/mediacontrol_windows.go.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'block_media_streaming')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->boolean('block_media_streaming')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'block_media_streaming')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropColumn('block_media_streaming');
            });
        }
    }
};
