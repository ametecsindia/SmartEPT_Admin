<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->date('work_date');
            $table->enum('source', ['CLIENT', 'BIOMETRIC', 'MANUAL', 'HRMS', 'SMARTPRS', 'CSV'])->default('CLIENT');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->timestamp('first_activity_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->integer('late_minutes')->default(0);
            $table->integer('early_logout_minutes')->default(0);
            $table->enum('status', ['PRESENT', 'ABSENT', 'HALF_DAY', 'ON_LEAVE', 'MISMATCH'])->default('PRESENT');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date', 'source']);
            $table->index(['company_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_logs');
    }
};
