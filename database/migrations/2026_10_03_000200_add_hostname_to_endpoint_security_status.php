<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 03-Oct-2026 — the PC's current name as the SmartEPT Agent Service reports it on every
 * security sync. enforcement_machines.hostname is fixed at enrolment and goes stale when a
 * PC is renamed (seen: DESKTOP-KN8IQRK renamed to AH). Additive, nullable, own table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('endpoint_security_status', 'hostname')) {
            Schema::table('endpoint_security_status', fn (Blueprint $t) => $t->string('hostname', 64)->nullable()->after('device_uuid'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('endpoint_security_status', 'hostname')) {
            Schema::table('endpoint_security_status', fn (Blueprint $t) => $t->dropColumn('hostname'));
        }
    }
};
