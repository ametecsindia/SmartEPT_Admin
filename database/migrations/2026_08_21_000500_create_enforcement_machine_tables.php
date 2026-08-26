<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The enforcement service's own identity.
 *
 * Decision 9: the Windows service holds a MACHINE credential, not one relayed
 * through the Electron agent. That is what lets it sync at boot with nobody
 * signed in — and a machine baseline that only works once somebody logs in
 * protects nothing during the window that matters most.
 *
 * Two tables:
 *
 *   enrollment_tokens      one-time secrets an admin mints for an installer run
 *   enforcement_machines   the endpoints that redeemed one
 *
 * The integrity key issued at enrolment is deliberately NOT stored here — see
 * the column comment below.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollment_tokens')) {
            Schema::create('enrollment_tokens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();

                // sha256 of the secret the installer was given. The secret
                // itself is shown once, at mint time, and never again — a
                // recoverable enrolment secret is a permanent way onto the
                // estate for anyone who reads the database.
                $t->char('token_hash', 64)->unique();

                $t->string('label', 120)->nullable();
                $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

                // A token that never expires and never runs out is a key left
                // under the mat. Both bounds are required.
                $t->timestamp('expires_at');
                $t->unsignedInteger('max_uses')->default(1);
                $t->unsignedInteger('uses')->default(0);

                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('enforcement_machines')) {
            Schema::create('enforcement_machines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();

                // Stable for the life of the install. What the endpoint files
                // itself under and what re-enrolment matches on.
                $t->string('machine_id', 128);

                $t->string('hostname', 191)->nullable();
                $t->string('os_version', 120)->nullable();
                $t->string('edition', 60)->nullable();

                // FULL | REDUCED | NONE — what this machine can actually
                // enforce, so the console never shows PROTECTED for a machine
                // that cannot enforce anything.
                $t->string('enforcement_level', 16)->default('NONE');
                // PROTECTED | AT_RISK | UNKNOWN — what it is achieving now.
                $t->string('enforcement_health', 16)->default('UNKNOWN');

                $t->unsignedInteger('applied_policy_version')->nullable();
                $t->string('enforcer_version', 32)->nullable();

                // The Windows SID last reported as signed in. AppLocker rules
                // are written against a SID, so an employee overlay cannot be
                // generated without one.
                $t->string('windows_sid', 184)->nullable();

                // Links this machine to the agent's device row where both are
                // installed, so the console can show one endpoint, not two.
                $t->string('device_uuid', 64)->nullable();

                // First 16 chars of sha256(integrity key), for support only.
                //
                // The key ITSELF is never stored. It travels once, at enrolment,
                // and the server has no reason to hold it: the endpoint uses it
                // to verify that a stored policy came from us, and nothing on
                // this side ever reads that store. An endpoint that loses its
                // identity re-enrols and gets a new key — which correctly makes
                // its old cached policies unreadable.
                $t->string('integrity_key_fp', 16)->nullable();

                $t->timestamp('enrolled_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'machine_id']);
                $t->index(['company_id', 'last_seen_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enforcement_machines');
        Schema::dropIfExists('enrollment_tokens');
    }
};
