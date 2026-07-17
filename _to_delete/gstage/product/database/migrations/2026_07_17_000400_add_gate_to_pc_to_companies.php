<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ejaz 17-Jul — the SmartEPT USP: GATE-TO-PC (Doc 11 v1.1, now going live).
 * When gate_enabled is on, the desktop agent will NOT start a work session
 * until a door/biometric IN punch for that employee has reached SmartEPT for
 * the day. No punch = no login = the clock only starts on real arrival.
 * gate_grace_minutes lets a punch that lands slightly after the PC boot still
 * open the gate retroactively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('gate_enabled')->default(false)->after('retention_keep_violation_evidence');
            $t->unsignedInteger('gate_grace_minutes')->default(0)->after('gate_enabled')
                ->comment('Allow an IN punch up to N minutes AFTER the agent asks, to still open the gate');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['gate_enabled', 'gate_grace_minutes']));
    }
};
