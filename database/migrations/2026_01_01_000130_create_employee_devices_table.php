<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->unique();

            $table->string('computer_name')->nullable();
            $table->string('os_version')->nullable();
            $table->string('windows_username')->nullable();
            $table->string('lan_ip')->nullable();
            $table->string('public_ip')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('wifi_ssid')->nullable();
            $table->string('processor')->nullable();
            $table->unsignedInteger('ram_gb')->nullable();
            $table->unsignedInteger('disk_gb')->nullable();
            $table->boolean('camera_available')->default(false);
            $table->boolean('microphone_available')->default(false);
            $table->string('app_version')->nullable();
            $table->string('service_version')->nullable();

            $table->enum('agent_health', ['HEALTHY', 'DEGRADED', 'STOPPED'])->default('HEALTHY');
            $table->enum('compliance_status', ['COMPLIANT', 'WARNING', 'NON_COMPLIANT', 'CRITICAL'])->default('COMPLIANT');
            $table->enum('current_status', ['ONLINE', 'IDLE', 'AWAY', 'OFFLINE'])->default('OFFLINE');
            $table->unsignedBigInteger('storage_usage_mb')->default(0);
            $table->unsignedInteger('sync_pending_count')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->string('device_token_hash')->nullable();

            $table->timestamps();
            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'current_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_devices');
    }
};
