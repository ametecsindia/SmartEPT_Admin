<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('biometric_employee_id');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('punch_type', ['IN', 'OUT', 'BREAK_IN', 'BREAK_OUT'])->default('IN');
            $table->timestamp('punched_at');
            $table->enum('verification_mode', ['FINGERPRINT', 'FACE', 'CARD', 'PIN'])->nullable();
            $table->string('raw_log_ref')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'biometric_employee_id']);
            $table->index(['company_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_logs');
    }
};
