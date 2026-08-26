<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit gate, and the state that goes with it.
 *
 * Decision 18: enforcement is never switched straight on. A tenant's policy runs
 * in AUDIT first, the endpoints report what they WOULD have blocked, and only a
 * clean report promotes it to ENFORCE. That needs two things the product does
 * not have:
 *
 *   enforcement_states         one row per company: which mode it is in, since
 *                              when, and who cleared it.
 *   enforcement_audit_events   what each endpoint would have blocked. This is
 *                              the report the promotion is gated on, and it is
 *                              also most of the Compliance Attestation report a
 *                              bank auditor asks for.
 *
 * Why this matters beyond the gate: our strict allow set covers %WINDIR% and
 * %PROGRAMFILES% only, so everything in the user profile is denied. A lot of
 * real business software installs into %LOCALAPPDATA% — Teams, VS Code, some
 * Chrome builds. Without an audit period we find that out from the client's
 * phone call instead of from their event log.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enforcement_states')) {
            Schema::create('enforcement_states', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();

                // OFF     nothing is enforced. The agent warns, as it always has.
                // AUDIT   rules are evaluated and reported, nothing is blocked.
                // ENFORCE rules actually block.
                $t->string('mode', 20)->default('OFF');

                // Bumped on every rule change. The agent heartbeat returns this
                // so an endpoint knows to re-sync without any push channel.
                $t->unsignedInteger('policy_version')->default(1);

                $t->timestamp('audit_started_at')->nullable();

                // Set when an admin promotes AUDIT -> ENFORCE against a report.
                $t->string('cleared_report_id', 64)->nullable();
                $t->foreignId('cleared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('cleared_at')->nullable();

                // Set when the kill switch is used, so the console can explain
                // why a tenant that was enforcing suddenly is not.
                $t->timestamp('disabled_at')->nullable();
                $t->string('disabled_reason', 191)->nullable();

                $t->timestamps();

                $t->unique('company_id');
            });
        }

        if (! Schema::hasTable('enforcement_audit_events')) {
            Schema::create('enforcement_audit_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
                $t->string('device_uuid', 64)->nullable();

                // APPLOCKER | FIREWALL | PROCESS — each layer reports separately,
                // because "the policy is applied" and "the block actually
                // happened" are different claims and we must not conflate them.
                $t->string('source', 20)->default('APPLOCKER');

                // WOULD_BLOCK (audit) | BLOCKED (enforced) | ALLOWED_BY_RULE
                $t->string('outcome', 20)->default('WOULD_BLOCK');

                // The path, package name or publisher the endpoint reported.
                $t->string('target', 512);
                // sha256(lower(target)). A 512-char column cannot carry a unique
                // index on MySQL with utf8mb4 (4 bytes per char blows the 3072
                // byte key limit), and paths are long. Hash it and index that.
                $t->char('target_hash', 64);
                $t->string('rule_name', 191)->nullable();

                // False for anything that is not one of the intended targets.
                // A single unexpected row is enough to refuse promotion — it is
                // a program the client actually uses.
                $t->boolean('expected')->default(false);

                // Set once the admin has allowed or dismissed this target, so a
                // handled item stops blocking promotion.
                $t->timestamp('resolved_at')->nullable();

                $t->unsignedInteger('occurrences')->default(1);
                $t->timestamp('first_seen_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'expected', 'resolved_at'], 'enf_audit_promotion_idx');
                $t->index(['company_id', 'last_seen_at']);
                // One row per (company, device, target) — endpoints report the
                // same blocked program dozens of times a day and we count them
                // rather than storing each one.
                $t->unique(['company_id', 'device_uuid', 'target_hash'], 'enf_audit_unique_target');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enforcement_audit_events');
        Schema::dropIfExists('enforcement_states');
    }
};
