<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent tamper protection (exit/uninstall lock): an admin-set password the
 * desktop agent requires before an employee can quit, stop, or uninstall it.
 * Stored encrypted at rest; the agent receives only a SHA-256 hash (in its
 * policy bundle) to verify a typed password locally. Plaintext never leaves here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'agent_exit_lock_enabled')) {
                $t->boolean('agent_exit_lock_enabled')->default(false)->after('gate_grace_minutes');
            }
            if (! Schema::hasColumn('companies', 'agent_exit_password')) {
                $t->text('agent_exit_password')->nullable()->after('agent_exit_lock_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['agent_exit_lock_enabled', 'agent_exit_password']);
        });
    }
};
