<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_break_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->enum('break_type', ['TEA', 'LUNCH', 'BIO', 'MEETING', 'TRAINING', 'PRAYER', 'CUSTOM'])->default('CUSTOM');
            $table->enum('source', ['MANUAL', 'AUTO_IDLE', 'BIOMETRIC'])->default('MANUAL');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->enum('approval_status', ['PENDING', 'APPROVED', 'REJECTED', 'NOT_REQUIRED'])->default('NOT_REQUIRED');
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_break_logs');
    }
};
