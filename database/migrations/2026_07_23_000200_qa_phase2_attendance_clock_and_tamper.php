<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA Phase 2 — attendance first/last login (A2), clock-diagnostic groundwork (A5/D6),
 * and the agent tamper log (A8). All additive & reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A2 — first login is write-once for the day; last login + final logout track the
        // real edges of the working day (overnight-safe day is computed in the controller).
        Schema::table('employee_attendance_logs', function (Blueprint $t) {
            if (! Schema::hasColumn('employee_attendance_logs', 'first_login_at')) {
                $t->timestamp('first_login_at')->nullable()->after('check_in_at');
            }
            if (! Schema::hasColumn('employee_attendance_logs', 'last_login_at')) {
                $t->timestamp('last_login_at')->nullable()->after('first_login_at');
            }
            if (! Schema::hasColumn('employee_attendance_logs', 'final_logout_at')) {
                $t->timestamp('final_logout_at')->nullable()->after('last_login_at');
            }
        });

        // A5/D6 — diagnostic columns: what time the DEVICE claimed vs when the SERVER
        // received it, plus a per-device monotonic sequence. Lets us detect a rolled-back
        // PC clock without ever letting local time shrink recorded minutes.
        Schema::table('employee_activity_events', function (Blueprint $t) {
            if (! Schema::hasColumn('employee_activity_events', 'device_reported_at')) {
                $t->timestamp('device_reported_at')->nullable()->after('ended_at');
            }
            if (! Schema::hasColumn('employee_activity_events', 'event_seq')) {
                $t->unsignedBigInteger('event_seq')->nullable()->after('device_reported_at');
            }
            if (! Schema::hasColumn('employee_activity_events', 'clock_drift_seconds')) {
                $t->integer('clock_drift_seconds')->nullable()->after('event_seq');
            }
        });

        // A8 — every exit / uninstall / override / unexpected service-stop attempt, whether
        // it succeeded, failed or was blocked. Feeds the admin tamper report.
        if (! Schema::hasTable('agent_tamper_events')) {
            Schema::create('agent_tamper_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
                $table->string('device_uuid')->nullable();
                $table->enum('event_type', [
                    'EXIT_ATTEMPT', 'EXIT_SUCCESS', 'UNINSTALL_ATTEMPT',
                    'GATE_OVERRIDE', 'SERVICE_STOP', 'WINDOW_CLOSE_BLOCKED',
                ]);
                $table->enum('outcome', ['SUCCESS', 'FAILED', 'BLOCKED', 'GRANTED'])->default('BLOCKED');
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('approver_user_id')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'created_at']);
                $table->index(['employee_id', 'event_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tamper_events');

        Schema::table('employee_activity_events', function (Blueprint $t) {
            $t->dropColumn(['device_reported_at', 'event_seq', 'clock_drift_seconds']);
        });

        Schema::table('employee_attendance_logs', function (Blueprint $t) {
            $t->dropColumn(['first_login_at', 'last_login_at', 'final_logout_at']);
        });
    }
};
