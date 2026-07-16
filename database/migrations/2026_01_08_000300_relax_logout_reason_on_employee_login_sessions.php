<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly attendance-completion job force-closes sessions the agent never
 * closed (crash / power cut) with logout_reason AUTO_CLOSED. The original enum
 * (USER/LOCK/SHUTDOWN/TIMEOUT) cannot hold that value, so relax to a string —
 * app code remains the source of truth for allowed values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_login_sessions', function (Blueprint $table) {
            $table->string('logout_reason', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally left as string — narrowing back would drop AUTO_CLOSED rows.
    }
};
