<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_app_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->string('app_name')->nullable();
            $table->string('process_name')->nullable();
            $table->string('window_title', 512)->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->default(0);
            $table->enum('category', [
                'PRODUCTIVE', 'NON_PRODUCTIVE', 'NEUTRAL', 'RESTRICTED',
                'BLOCKED', 'CLIENT_REQUIRED', 'SYSTEM', 'COMMUNICATION',
            ])->default('NEUTRAL');
            $table->enum('compliance_status', ['OK', 'VIOLATION'])->default('OK');
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'start_at']);
            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_app_usage_logs');
    }
};
