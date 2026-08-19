<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATED GATE EXCLUSIONS (Ejaz, 18-Aug-2026 — same day, second pass).
 *
 * Real reasons an exclusion is granted are almost always temporary:
 *   - "the door reader is dead this week"        → exclude the BRANCH, 20–22 Aug
 *   - "her fingerprint won't read since Monday"  → exclude the EMPLOYEE, 18–25 Aug
 * A permanent exclusion for either of those is how a security control quietly rots:
 * nobody remembers to take it off, and six months later half the company can sign in
 * without ever touching the door.
 *
 * So every level that can carry gate_mode now also carries a validity window:
 *   gate_mode_from  — first date the exclusion applies (NULL = applies immediately)
 *   gate_mode_until — last date it applies, INCLUSIVE (NULL = no expiry / permanent)
 * Outside the window the level reads as if gate_mode were NULL — the walk simply
 * continues to the level above, so the gate re-arms itself with no admin action and
 * no scheduled job. Both dates NULL = the permanent exclusion shipped earlier today,
 * unchanged, which is why this migration needs no data backfill.
 *
 * gate_mode_reason / gate_mode_by_user_id record WHY and WHO, stamped server-side from
 * the authenticated admin. The audit log already records the change; these keep the
 * answer on the row itself, where the console shows it.
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
                if (! Schema::hasColumn($t, 'gate_mode_from')) {
                    $table->date('gate_mode_from')->nullable();
                }
                if (! Schema::hasColumn($t, 'gate_mode_until')) {
                    $table->date('gate_mode_until')->nullable();
                }
                if (! Schema::hasColumn($t, 'gate_mode_reason')) {
                    $table->string('gate_mode_reason', 255)->nullable();
                }
                if (! Schema::hasColumn($t, 'gate_mode_by_user_id')) {
                    $table->unsignedBigInteger('gate_mode_by_user_id')->nullable();
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
                foreach (['gate_mode_from', 'gate_mode_until', 'gate_mode_reason', 'gate_mode_by_user_id'] as $col) {
                    if (Schema::hasColumn($t, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
