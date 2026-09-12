<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level switch: remove the file picker from every browser on every
 * enrolled PC (Chrome, Edge, Brave, Firefox), so no website can receive an
 * upload. Same shape as block_camera_device: machine-wide, admin-controlled,
 * default off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'block_browser_uploads')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->boolean('block_browser_uploads')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'block_browser_uploads')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropColumn('block_browser_uploads');
            });
        }
    }
};
