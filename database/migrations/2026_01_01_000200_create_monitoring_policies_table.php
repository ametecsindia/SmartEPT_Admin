<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('tracking_enabled')->default(true);
            $table->boolean('working_hours_only')->default(true);
            $table->time('working_start')->default('09:00:00');
            $table->time('working_end')->default('18:00:00');
            $table->unsignedInteger('tracking_interval_seconds')->default(30);
            $table->unsignedInteger('idle_threshold_seconds')->default(120);
            $table->unsignedInteger('away_threshold_seconds')->default(60);
            $table->boolean('app_usage_enabled')->default(true);
            $table->boolean('website_usage_enabled')->default(true);
            $table->boolean('network_compliance_enabled')->default(false);
            $table->boolean('usb_tracking_enabled')->default(false);
            $table->boolean('vpn_proxy_detection_enabled')->default(false);
            $table->boolean('remote_access_detection_enabled')->default(false);
            $table->boolean('employee_status_visible')->default(true);
            $table->boolean('consent_required')->default(true);
            $table->unsignedInteger('data_retention_days')->default(90);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_policies');
    }
};
