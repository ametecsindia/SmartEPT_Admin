<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee enforcement.
 *
 * Until now enforcement was one switch for a whole tenant: every PC blocked the
 * same things for everybody. That is wrong for a real office. A collection agent
 * must not reach WhatsApp; the director who signs the cheques uses it to talk to
 * clients, and on a shared PC those two people sit at the same desk on different
 * shifts.
 *
 * Deliberately the SAME shape as tracking_mode, at the same six levels, resolved
 * by the same most-specific-wins rule. A second mechanism that does almost the
 * same thing is how a console ends up with two settings that contradict each
 * other and nobody can say which wins.
 *
 *     device -> employee -> team -> department -> branch -> company
 *
 * NULL at every level means "inherit". All six NULL resolves to ENFORCED, which
 * is safe: the tenant-wide EnforcementState still has to be switched to ENFORCE
 * before anything is blocked at all, so this default can never start blocking
 * for somebody on its own.
 */
return new class extends Migration
{
    /**
     * Six levels, exactly the ones tracking_mode uses.
     *
     * employee_devices is included because one person can have a desktop that is
     * enforced and a laptop that is not — the tracking hierarchy already allows
     * that, and enforcement diverging from it would be a surprise.
     */
    private const TABLES = [
        'employee_devices',
        'employees',
        'teams',
        'departments',
        'branches',
        'companies',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'enforcement_mode')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                // Nullable with no default: NULL is "inherit", and that has to be
                // distinguishable from an explicit choice. A default of ENFORCED
                // here would silently pin every existing row to the top of the
                // hierarchy and make inheritance impossible to express.
                $t->string('enforcement_mode', 16)->nullable()->after(
                    Schema::hasColumn($table, 'tracking_mode') ? 'tracking_mode' : 'id'
                );
            });
        }

        // The window an exemption applies for, on the employee row only.
        //
        // Same idea as the gate-to-PC exclusion the console already offers: "let
        // Priya use WhatsApp for the three days she is covering client calls" has
        // to expire by itself. An exemption that needs an administrator to
        // remember to remove it is an exemption that becomes permanent.
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'enforcement_exempt_from')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->date('enforcement_exempt_from')->nullable()->after('enforcement_mode');
                $t->date('enforcement_exempt_until')->nullable()->after('enforcement_exempt_from');
                $t->string('enforcement_exempt_reason', 191)->nullable()->after('enforcement_exempt_until');
                // Who granted it. An exemption from blocking is a security
                // decision, and one nobody can attribute is one nobody owns.
                $t->unsignedBigInteger('enforcement_exempt_by_user_id')->nullable()->after('enforcement_exempt_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $t) {
                foreach ([
                    'enforcement_exempt_from',
                    'enforcement_exempt_until',
                    'enforcement_exempt_reason',
                    'enforcement_exempt_by_user_id',
                ] as $col) {
                    if (Schema::hasColumn('employees', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'enforcement_mode')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('enforcement_mode'));
            }
        }
    }
};
