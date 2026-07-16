<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_website_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->string('browser')->nullable();
            $table->string('domain')->nullable();
            $table->string('full_url', 1024)->nullable();
            $table->string('page_title', 512)->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->default(0);
            $table->enum('category', [
                'PRODUCTIVE', 'NON_PRODUCTIVE', 'NEUTRAL', 'RESTRICTED', 'BLOCKED',
                'BANKING_CRM', 'COMMUNICATION', 'TRAINING', 'CLIENT_SPECIFIC',
            ])->default('NEUTRAL');
            $table->enum('compliance_status', ['OK', 'VIOLATION'])->default('OK');
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'start_at'], 'ept_weblog_co_emp_start_idx');
            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_website_usage_logs');
    }
};
