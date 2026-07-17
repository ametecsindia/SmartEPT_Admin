<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloud biometric sync (Ejaz 17-Jul): a punch device can now be a CLOUD
 * ATTENDANCE API (eTimeOffice-style) that SmartEPT polls hourly. Punches
 * import into BiometricLog and fold into Attendance & payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->boolean('sync_enabled')->default(false)->after('status');
            $table->string('provider', 120)->nullable()->after('sync_enabled');
            $table->string('api_base_url', 500)->nullable()->after('provider');
            $table->string('api_endpoint', 190)->nullable()->after('api_base_url');
            $table->string('corporate_id', 120)->nullable()->after('api_endpoint');
            $table->string('api_username', 190)->nullable()->after('corporate_id');
            $table->text('api_password')->nullable()->after('api_username'); // encrypted cast
            $table->string('employee_code_filter', 64)->nullable()->after('api_password');
            $table->string('employee_id_prefix', 32)->nullable()->after('employee_code_filter');
            $table->string('in_machine_id', 64)->nullable()->after('employee_id_prefix');
            $table->string('out_machine_id', 64)->nullable()->after('in_machine_id');
            $table->string('last_sync_result', 500)->nullable()->after('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropColumn([
                'sync_enabled', 'provider', 'api_base_url', 'api_endpoint', 'corporate_id',
                'api_username', 'api_password', 'employee_code_filter', 'employee_id_prefix',
                'in_machine_id', 'out_machine_id', 'last_sync_result',
            ]);
        });
    }
};
