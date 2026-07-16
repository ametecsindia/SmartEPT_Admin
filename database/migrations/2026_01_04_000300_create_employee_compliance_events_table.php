<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unified violation stream — every compliance domain (app, website, network, USB,
 * device, agent, webcam) emits here. Feeds the compliance score and manager alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_compliance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->string('event_type'); // BLOCKED_APP_OPENED, BLOCKED_WEBSITE_OPENED, ...
            $table->enum('event_category', ['APP', 'WEBSITE', 'NETWORK', 'WEBCAM', 'USB', 'DEVICE', 'AGENT'])->default('APP');
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->default('MEDIUM');
            $table->string('description')->nullable();
            $table->string('detected_value')->nullable();
            $table->string('expected_value')->nullable();
            $table->string('action_taken')->nullable();
            $table->boolean('screenshot_captured')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'started_at'], 'ept_compev_co_emp_started_idx');
            $table->index(['company_id', 'event_category', 'severity'], 'ept_compev_co_cat_sev_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compliance_events');
    }
};
