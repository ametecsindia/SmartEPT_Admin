<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is signed in to the agent on this PC right now.
 *
 * Blocking follows the PERSON, not the machine. The endpoint reports this on
 * every heartbeat and the server answers for that employee — their rules, or
 * nothing at all when they have none or nobody is signed in.
 *
 * Stored rather than only used in flight so the console can answer "why is that
 * PC not blocking anything" at a glance instead of by investigation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enforcement_machines')
            || Schema::hasColumn('enforcement_machines', 'signed_in_employee_id')) {
            return;
        }

        Schema::table('enforcement_machines', function (Blueprint $t) {
            // Nullable and unconstrained: NULL means nobody is signed in, which
            // is a normal state, and a deleted employee must not stop a machine
            // reporting in.
            $t->unsignedBigInteger('signed_in_employee_id')->nullable()->after('windows_sid');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('enforcement_machines') && Schema::hasColumn('enforcement_machines', 'signed_in_employee_id')) {
            Schema::table('enforcement_machines', fn (Blueprint $t) => $t->dropColumn('signed_in_employee_id'));
        }
    }
};
