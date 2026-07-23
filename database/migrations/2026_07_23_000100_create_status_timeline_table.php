<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA Phase 1 — AUTHORITATIVE STATUS TIMELINE (Part C; fixes A4/A6/A7/B1/B2/B3/B15).
 *
 * Until now an employee's "current status" had to be inferred by racing four separate
 * tables (activity events, idle logs, break logs, meeting sessions), so the console and
 * the agent could disagree and an employee could appear active AND on-break at once.
 *
 * status_timeline is ONE ordered stream of non-overlapping segments — exactly one open
 * (ended_at IS NULL) segment per employee at any moment — written through StatusService.
 * The legacy tables keep being written (dual-write) so existing reports are untouched;
 * this table becomes the single source for "what is this person doing right now" and for
 * today's active / idle / break / meeting totals.
 *
 * Additive & reversible: no existing column is touched; down() drops only this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();

            $table->enum('state', [
                'OFFLINE', 'LOGGED_IN', 'ACTIVE', 'IDLE',
                'TEA_BREAK', 'LUNCH_BREAK', 'OTHER_BREAK', 'MEETING',
            ]);

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();

            $table->unsignedBigInteger('meeting_id')->nullable();
            $table->enum('source', ['AGENT', 'BIOMETRIC', 'SYSTEM', 'ADMIN'])->default('AGENT');

            // Idempotency key — a repeated event (network retry / rapid double-click)
            // carrying the same uuid is a no-op. Nullable: not every internal transition
            // has one, and MySQL/sqlite both allow multiple NULLs under a unique index.
            $table->string('event_uuid')->nullable()->unique();

            $table->enum('idle_source', ['INACTIVITY', 'LOCK', 'DISPLAY_OFF', 'SUSPEND', 'DISCONNECT'])->nullable();

            $table->timestamps();

            // Read paths: "segments for employee E ordered in time" and the tenant roll-up.
            $table->index(['employee_id', 'started_at']);
            $table->index(['company_id', 'started_at']);
            // The open-segment lookup (ended_at IS NULL) — StatusService's hot path.
            $table->index(['employee_id', 'ended_at']);
        });

        // The "exactly one open segment per employee" invariant is enforced in code:
        // StatusService::transition() serialises concurrent events with a row lock inside a
        // DB transaction and closes any open segment before opening the next. A portable
        // functional-unique index was deliberately NOT used — the required MySQL 8 syntax is
        // rejected by MariaDB (common on Laragon), which would fail the migration on-site.
    }

    public function down(): void
    {
        Schema::dropIfExists('status_timeline');
    }
};
