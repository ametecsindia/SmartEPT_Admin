<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 10 — server-enforced single active agent session per employee.
 * session_status is the login lifecycle (distinct from current_status, which is the
 * activity state ONLINE/IDLE/AWAY/OFFLINE). last_login_at + force_logout_at give the
 * admin session view its facts. "Active" = session_status ACTIVE + a recent heartbeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->enum('session_status', ['ACTIVE', 'LOGGED_OUT', 'FORCE_LOGOUT'])->default('LOGGED_OUT')->after('current_status');
            $table->timestamp('last_login_at')->nullable()->after('registered_at');
            $table->timestamp('force_logout_at')->nullable()->after('last_login_at');
            $table->index(['company_id', 'employee_id', 'session_status'], 'ept_dev_emp_session_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->dropIndex('ept_dev_emp_session_idx');
            $table->dropColumn(['session_status', 'last_login_at', 'force_logout_at']);
        });
    }
};
