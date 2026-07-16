<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedBigInteger('present_seconds')->default(0);
            $table->unsignedBigInteger('active_seconds')->default(0);
            $table->unsignedBigInteger('idle_seconds')->default(0);
            $table->unsignedBigInteger('away_seconds')->default(0);
            $table->unsignedBigInteger('break_seconds')->default(0);
            $table->unsignedBigInteger('productive_app_seconds')->default(0);
            $table->unsignedBigInteger('non_productive_seconds')->default(0);
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('last_logout_at')->nullable();
            $table->integer('late_minutes')->default(0);
            $table->integer('early_logout_minutes')->default(0);
            $table->unsignedInteger('violation_count')->default(0);
            $table->unsignedInteger('screenshot_count')->default(0);
            $table->decimal('productivity_score', 5, 2)->default(0);
            $table->decimal('compliance_score', 5, 2)->default(100);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['company_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_daily_summaries');
    }
};
