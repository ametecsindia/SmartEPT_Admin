<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift-bounded agent sign-in (Ejaz, 26-Aug-2026).
 *
 * "The employee should not be able to sign in to the Agent app if it is not within the
 * employee's shift. For example the employee's shift is 9:00 AM to 18:00, it should reject the
 * sign in outside this duration."
 *
 * Off by default (false), and deliberately so: switching this on for every existing tenant at
 * upgrade time would lock out anyone whose real hours drift from their configured shift, on a
 * product whose whole job is to be running on the employee's PC. An admin turns it on per shift.
 *
 * Lives ONLY on `shifts`, not also on attendance_policies like
 * post_shift_auto_logout_minutes: the window being enforced IS the shift's, so the switch
 * belongs beside the times it refers to — and it avoids the "policy exists but was never
 * assigned, so nothing happens" trap that already cost a debugging session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('restrict_login_to_shift')->default(false)->after('post_shift_auto_logout_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('restrict_login_to_shift');
        });
    }
};
