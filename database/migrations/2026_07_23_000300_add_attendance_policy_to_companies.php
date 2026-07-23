<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA Phase 3 (B7/B8 + D2/D3) — attendance derivation policy.
 *
 * Puts the "how late/checkout are decided" knobs where a non-technical admin
 * can set them per company, and records on each attendance row HOW check-in,
 * check-out and late were derived (raw vs derived stay separate — payroll audit).
 * All additive & reversible; every column guarded so a partial re-run is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            // D2 — which clock decides the effective arrival used for late minutes.
            if (! Schema::hasColumn('companies', 'late_arrival_source')) {
                $t->enum('late_arrival_source', ['AGENT_LOGIN', 'BIOMETRIC_IN', 'LATER_OF_BOTH', 'SHIFT_DEFAULT'])
                    ->default('LATER_OF_BOTH')
                    ->after('biometric_gate');
            }
            // D3 — how the day's checkout is chosen from the raw punches / sessions.
            if (! Schema::hasColumn('companies', 'checkout_policy')) {
                $t->enum('checkout_policy', ['LAST_OUT_AFTER_GRACE', 'FINAL_OUT_SHIFT_WINDOW', 'EXPLICIT_END_PUNCH', 'MANUAL'])
                    ->default('LAST_OUT_AFTER_GRACE')
                    ->after('late_arrival_source');
            }
            // Grace past shift end before a trailing OUT is treated as the final checkout.
            if (! Schema::hasColumn('companies', 'checkout_grace_minutes')) {
                $t->unsignedInteger('checkout_grace_minutes')->default(30)->after('checkout_policy');
            }
        });

        // Attendance rows carry the derivation provenance so the sheet shows HOW each
        // value was reached (which never overwrites the raw biometric_logs).
        Schema::table('employee_attendance_logs', function (Blueprint $t) {
            if (! Schema::hasColumn('employee_attendance_logs', 'check_in_source')) {
                $t->string('check_in_source', 24)->nullable()->after('check_out_at');
            }
            if (! Schema::hasColumn('employee_attendance_logs', 'check_out_source')) {
                $t->string('check_out_source', 24)->nullable()->after('check_in_source');
            }
            if (! Schema::hasColumn('employee_attendance_logs', 'arrival_source_used')) {
                $t->string('arrival_source_used', 24)->nullable()->after('check_out_source');
            }
            if (! Schema::hasColumn('employee_attendance_logs', 'derivation_note')) {
                $t->text('derivation_note')->nullable()->after('arrival_source_used');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendance_logs', function (Blueprint $t) {
            foreach (['check_in_source', 'check_out_source', 'arrival_source_used', 'derivation_note'] as $c) {
                if (Schema::hasColumn('employee_attendance_logs', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        Schema::table('companies', function (Blueprint $t) {
            foreach (['late_arrival_source', 'checkout_policy', 'checkout_grace_minutes'] as $c) {
                if (Schema::hasColumn('companies', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
