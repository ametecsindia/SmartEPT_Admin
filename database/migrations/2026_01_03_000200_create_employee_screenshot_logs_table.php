<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_screenshot_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->foreignId('storage_file_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->string('active_app')->nullable();
            $table->string('window_title', 512)->nullable();
            $table->string('website_domain')->nullable();
            $table->enum('trigger_reason', ['INTERVAL', 'RANDOM', 'VIOLATION', 'BLOCKED_APP', 'BLOCKED_SITE'])->default('INTERVAL');
            $table->unsignedBigInteger('screenshot_policy_id')->nullable();
            $table->unsignedBigInteger('violation_id')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'captured_at'], 'ept_sslog_co_emp_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_screenshot_logs');
    }
};
