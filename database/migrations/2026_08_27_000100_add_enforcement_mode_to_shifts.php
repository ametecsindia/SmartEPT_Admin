<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift-level enforcement.
 *
 * 27-Aug-2026 (Ejaz): "enforcement option ... update it for Branch, Department, Team and Shift
 * level, so that this can be applied or exempted appropriately."
 *
 * Branch, department, team, employee, device and company already carry `enforcement_mode`
 * (2026_08_24_000100). Shift was the one level in the organisation that did not, and it is the
 * one an office actually reaches for: the same person is enforced on the day shift and needs
 * the remote-support tool at 2am, and nothing about their team, department or branch changes
 * between those two facts.
 *
 * Deliberately the same column as the other five — same name, same width, same nullable-means-
 * inherit rule — so there is one concept, not a shift-shaped special case. It slots into
 * PolicyResolver::effectiveEnforcementMode() between EMPLOYEE and TEAM: a shift is chosen per
 * person, so it is more specific than the team they belong to, and "the night shift may use
 * this" has to beat "the support team is enforced" rather than lose to it.
 *
 * NULL at every level still resolves to ENFORCED, and the tenant switch still has to be ON
 * before anything is blocked at all, so adding this column changes nothing on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shifts') || Schema::hasColumn('shifts', 'enforcement_mode')) {
            return;
        }

        Schema::table('shifts', function (Blueprint $t) {
            // No default. NULL is "inherit", and that has to stay distinguishable from an
            // administrator explicitly choosing ENFORCED — a default would pin every existing
            // shift to the top of the hierarchy and make inheritance impossible to express.
            $t->string('enforcement_mode', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('shifts') && Schema::hasColumn('shifts', 'enforcement_mode')) {
            Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn('enforcement_mode'));
        }
    }
};
