<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GATE-TO-PC EXCLUSION POLICY (Ejaz, 18-Aug-2026).
 *
 * Gate-to-PC is a company-wide switch, which is too blunt in practice: night-shift
 * staff, field engineers, work-from-home developers and visiting contractors reach a
 * PC without ever passing the door reader. Before this, the only escape was the
 * one-shot emergency override (a faked IN punch, good for that day only) — so admins
 * were re-approving the same people every morning.
 *
 * gate_mode is a nullable, tri-state override on every level of the org chain
 * (device > employee > team > department > branch). NULL = inherit from the level
 * above; the first level in the walk that sets a value wins:
 *   EXCLUDED — this person may start a work session on credentials alone.
 *   REQUIRED — this person must punch in, even if a level ABOVE them is excluded.
 *              (Exclude the whole branch, but keep the security team gated.)
 *
 * Nothing set anywhere = the company-level Gate-to-PC setting applies unchanged,
 * so this migration is a no-op for every existing install.
 *
 * Deliberately NOT on `companies`: the company already has biometric_gate
 * ('auto'|'on'|'off') and gate_enabled, and a third company-level switch saying the
 * same thing is how the "which flag wins?" bugs start.
 */
return new class extends Migration
{
    private array $orgTables = ['employees', 'employee_devices', 'teams', 'departments', 'branches'];

    public function up(): void
    {
        foreach ($this->orgTables as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }

            Schema::table($t, function (Blueprint $table) use ($t) {
                if (! Schema::hasColumn($t, 'gate_mode')) {
                    $table->string('gate_mode', 16)->nullable()
                        ->comment('Gate-to-PC override: EXCLUDED | REQUIRED. NULL = inherit.');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->orgTables as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }

            Schema::table($t, function (Blueprint $table) use ($t) {
                if (Schema::hasColumn($t, 'gate_mode')) {
                    $table->dropColumn('gate_mode');
                }
            });
        }
    }
};
