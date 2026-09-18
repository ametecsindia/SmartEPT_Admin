<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SmartEPT LiveView — Phase 3 POC. This one table is both the concurrency ledger
 * and the audit trail (plan §5.1 / §11 — no second table): "currently active" is
 * always COUNT(*) WHERE ended_at IS NULL, computed at read time, never a maintained
 * flag (the same rule finding 1.8 already established for device online/offline).
 *
 * POC scope deliberately leaves out what Phase 4+ adds: no heartbeat-timeout sweep
 * yet (session ends only on an explicit Stop), no capacity/licence enforcement yet.
 * Every column below is already sized for that later work so no later migration
 * has to widen this table — only the enforcement code around it grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liveview_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->unsignedTinyInteger('monitor_index')->default(0);
            $table->enum('quality', ['data_saver', 'low', 'high'])->default('low');
            $table->enum('status', ['connecting', 'live', 'paused', 'ended'])->default('connecting');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable(); // NULL = currently active
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('admin_ip', 64)->nullable();
            $table->string('termination_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'ended_at'], 'ept_lvs_co_active_idx');
            $table->index(['employee_id', 'started_at'], 'ept_lvs_emp_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liveview_sessions');
    }
};
