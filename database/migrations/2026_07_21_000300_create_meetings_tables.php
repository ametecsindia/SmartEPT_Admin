<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 2 — Meetings. HR/Admin/Manager/TL schedule meetings with a participant list;
 * a participant may put themselves in "Meeting" status ONLY inside the scheduled window
 * (server-validated). Meeting time is PRODUCTIVE, never a break. Three tables: the
 * meeting, its participants, and each employee's actual meeting session(s).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('purpose')->nullable();
            $table->date('meeting_date');
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->enum('status', ['SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('SCHEDULED');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'start_at']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('employee_meeting_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'meeting_id']);
            $table->index(['employee_id', 'actual_start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_meeting_sessions');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
