<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 25-Jul punch list (EPT25-08/10/11 + EPT25-07). Meetings no longer auto-complete at
 * the scheduled end — the organiser (or an admin/super/HR) must End them, and a
 * long-stop auto-closes anything nobody ended. This adds the columns that model an
 * ACTUAL end and the richer status set.
 *
 * All additive & guarded so a partial re-run is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            if (! Schema::hasColumn('meetings', 'actual_end_at')) {
                $t->timestamp('actual_end_at')->nullable()->after('end_at');
            }
            if (! Schema::hasColumn('meetings', 'ended_by_user_id')) {
                $t->unsignedBigInteger('ended_by_user_id')->nullable()->after('created_by_user_id');
            }
        });

        // Widen the status enum: add NO_SHOW (started, nobody joined) and AUTO_CLOSED
        // (ran past the long-stop with attendance but nobody pressed End). MySQL only;
        // guarded so non-MySQL drivers (sqlite tests) simply skip the enum widen.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE meetings MODIFY COLUMN status "
                . "ENUM('SCHEDULED','IN_PROGRESS','COMPLETED','CANCELLED','NO_SHOW','AUTO_CLOSED') "
                . "NOT NULL DEFAULT 'SCHEDULED'"
            );
        }
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            foreach (['actual_end_at', 'ended_by_user_id'] as $c) {
                if (Schema::hasColumn('meetings', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE meetings MODIFY COLUMN status "
                . "ENUM('SCHEDULED','IN_PROGRESS','COMPLETED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED'"
            );
        }
    }
};
