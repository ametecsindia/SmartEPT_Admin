<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_webcam_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->foreignId('storage_file_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->enum('trigger_reason', ['INTERVAL', 'VIOLATION', 'ATTENDANCE'])->default('INTERVAL');
            $table->string('presence_status')->nullable();
            $table->unsignedInteger('face_count')->default(0);
            $table->unsignedBigInteger('webcam_policy_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_webcam_logs');
    }
};
