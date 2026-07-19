<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloud multi-tenancy (EPT-27): links a hosted Company back to its SmartEPT
 * Central tenant so provisioning is idempotent (re-provision reuses the Company).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'external_tenant_id')) {
                $table->string('external_tenant_id')->nullable()->unique()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'external_tenant_id')) {
                $table->dropUnique(['external_tenant_id']);
                $table->dropColumn('external_tenant_id');
            }
        });
    }
};
