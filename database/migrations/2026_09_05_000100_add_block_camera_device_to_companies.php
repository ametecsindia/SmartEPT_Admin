<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level switch: disable the camera DEVICE on every enrolled PC.
 *
 * Machine-wide and admin-controlled, like block_removable_storage: the service
 * disables the camera in Device Manager (plus the machine-wide consent deny), so
 * no application, browser or user can reach it until the admin turns it back on.
 * Default false: an upgrade never starts blocking cameras on a tenant that did
 * not ask for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'block_camera_device')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->boolean('block_camera_device')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'block_camera_device')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropColumn('block_camera_device');
            });
        }
    }
};
