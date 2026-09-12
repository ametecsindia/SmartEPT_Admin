<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level switch: block USB / removable storage on every enrolled PC.
 *
 * Unlike the app protections, this is a real device-access block Windows itself
 * enforces at the driver level (RemovableStorageDevices policy + the USBSTOR
 * service). It is machine-wide and admin-controlled, so it lives on the company,
 * not on a per-app rule.
 *
 * Default false: an upgrade never starts blocking USB on a tenant that did not
 * ask for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'block_removable_storage')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->boolean('block_removable_storage')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'block_removable_storage')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropColumn('block_removable_storage');
            });
        }
    }
};
