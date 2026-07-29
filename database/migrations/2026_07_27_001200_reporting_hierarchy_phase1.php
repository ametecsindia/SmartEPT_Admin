<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R6 Reporting hierarchy — Phase 1 foundation.
 *  - employees.reporting_manager_user_id (the renamed "Team" reporting link -> a user)
 *  - employees.system_role (authorised role, separate from editable designation text)
 *  - users.reporting_manager_user_id (supervisor chain, for future roll-ups)
 *  Backfills reporting_manager_user_id from the existing team lead -> manager -> manager_user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            if (! Schema::hasColumn('employees', 'reporting_manager_user_id')) {
                $t->unsignedBigInteger('reporting_manager_user_id')->nullable()->after('manager_user_id')->index();
            }
            if (! Schema::hasColumn('employees', 'system_role')) {
                $t->string('system_role', 20)->nullable()->after('reporting_manager_user_id');
            }
        });
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'reporting_manager_user_id')) {
                $t->unsignedBigInteger('reporting_manager_user_id')->nullable()->after('id')->index();
            }
        });

        // Backfill reporting_manager_user_id from existing team/manager links. Raw DB::table
        // to bypass the BelongsToCompany global scope (no auth context in a CLI migration).
        foreach (DB::table('employees')->whereNull('reporting_manager_user_id')->get() as $e) {
            $team = $e->team_id ? DB::table('teams')->where('id', $e->team_id)->first() : null;
            $rm = ($team->team_leader_user_id ?? null) ?: ($team->manager_user_id ?? null) ?: ($e->manager_user_id ?? null);
            if ($rm) {
                DB::table('employees')->where('id', $e->id)->update(['reporting_manager_user_id' => $rm]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->dropColumn(['reporting_manager_user_id', 'system_role']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('reporting_manager_user_id');
        });
    }
};
