<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ejaz 17-Jul: cloud auto-provisioning. When SmartEPT Central provisions a
 * SmartEPT-Managed-Cloud order it calls this app to stand up the tenant's
 * hosted console. external_tenant_id maps the Central tenant → this Company so
 * the provisioning call is idempotent (retries/re-provisions never duplicate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'external_tenant_id')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->string('external_tenant_id')->nullable()->unique()->after('code')
                    ->comment('SmartEPT Central tenant id — set for cloud-provisioned companies');
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropUnique(['external_tenant_id']);
            $t->dropColumn('external_tenant_id');
        });
    }
};
