<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('device_serial')->nullable();
            $table->string('location')->nullable();
            $table->string('ip_address')->nullable();
            $table->enum('integration_method', ['DIRECT_PULL', 'MIDDLEWARE_PUSH', 'CSV_IMPORT', 'HRMS_API'])->default('MIDDLEWARE_PUSH');
            $table->string('vendor')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->index(['company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
