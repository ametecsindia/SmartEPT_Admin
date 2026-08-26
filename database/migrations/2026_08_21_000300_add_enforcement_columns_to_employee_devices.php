<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each endpoint can enforce, what it is actually achieving, and whose
 * Windows account the policy was applied to.
 *
 * The console has to be able to show "enforcement requested" next to
 * "enforcement achieved". Today it can only show the first, which is precisely
 * the failure that cost the collection agency: the screen said Block and the
 * machine did nothing, and nobody could see the difference.
 *
 * windows_sid is the other half. AppLocker rules are written against a SID, not
 * an account name, so an employee overlay cannot be generated without one — and
 * a policy sitting on an endpoint with no user reference cannot be traced back
 * to the employee it belongs to, or reverted when they sign out.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_devices')) {
            return;
        }

        Schema::table('employee_devices', function (Blueprint $t) {
            if (! Schema::hasColumn('employee_devices', 'enforcer_version')) {
                $t->string('enforcer_version', 32)->nullable()->after('service_version');
            }
            if (! Schema::hasColumn('employee_devices', 'enforcement_level')) {
                // FULL     AppLocker + firewall + process termination all available
                // REDUCED  no AppLocker (unverified edition, or AppIDSvc unavailable)
                // NONE     nothing can be enforced here; never claim PROTECTED
                $t->string('enforcement_level', 16)->nullable()->after('enforcer_version');
            }
            if (! Schema::hasColumn('employee_devices', 'enforcement_health')) {
                // PROTECTED  policy applied AND a deny was proven to deny
                // AT_RISK    policy applied but not actually enforcing (the classic
                //            case: AppIDSvc stopped, so every rule goes silent)
                // UNKNOWN    no enforcement service has reported yet
                $t->string('enforcement_health', 16)->nullable()->after('enforcement_level');
            }
            if (! Schema::hasColumn('employee_devices', 'applied_policy_version')) {
                $t->unsignedInteger('applied_policy_version')->nullable()->after('enforcement_health');
            }
            if (! Schema::hasColumn('employee_devices', 'windows_sid')) {
                // A Windows SID is at most 184 characters.
                $t->string('windows_sid', 184)->nullable()->after('applied_policy_version');
            }
            if (! Schema::hasColumn('employee_devices', 'enforcement_reported_at')) {
                $t->timestamp('enforcement_reported_at')->nullable()->after('windows_sid');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_devices')) {
            return;
        }

        Schema::table('employee_devices', function (Blueprint $t) {
            foreach ([
                'enforcer_version',
                'enforcement_level',
                'enforcement_health',
                'applied_policy_version',
                'windows_sid',
                'enforcement_reported_at',
            ] as $col) {
                if (Schema::hasColumn('employee_devices', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
