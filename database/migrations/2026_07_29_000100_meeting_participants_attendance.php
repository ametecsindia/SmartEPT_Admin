<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part B §16 — meeting_participants gains first-class attendance fields so a Join
 * (from the notification popup, the scheduler, the desktop agent or the admin
 * console) records who actually attended, from where, and for how long — instead
 * of attendance living only in employee_meeting_sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_participants', function (Blueprint $t) {
            if (! Schema::hasColumn('meeting_participants', 'participant_role')) {
                $t->string('participant_role', 20)->default('participant')->after('employee_id');
            }
            if (! Schema::hasColumn('meeting_participants', 'invited_at')) {
                $t->timestamp('invited_at')->nullable()->after('participant_role');
            }
            if (! Schema::hasColumn('meeting_participants', 'joined_at')) {
                $t->timestamp('joined_at')->nullable()->after('invited_at');
            }
            if (! Schema::hasColumn('meeting_participants', 'left_at')) {
                $t->timestamp('left_at')->nullable()->after('joined_at');
            }
            if (! Schema::hasColumn('meeting_participants', 'attendance_status')) {
                $t->string('attendance_status', 20)->default('INVITED')->after('left_at');
            }
            if (! Schema::hasColumn('meeting_participants', 'attended_seconds')) {
                $t->unsignedInteger('attended_seconds')->default(0)->after('attendance_status');
            }
            if (! Schema::hasColumn('meeting_participants', 'join_source')) {
                $t->string('join_source', 30)->nullable()->after('attended_seconds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meeting_participants', function (Blueprint $t) {
            $t->dropColumn(['participant_role', 'invited_at', 'joined_at', 'left_at',
                'attendance_status', 'attended_seconds', 'join_source']);
        });
    }
};
