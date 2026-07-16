<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_login_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->enum('session_type', ['CLIENT', 'SYSTEM'])->default('CLIENT');
            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->string('login_ip')->nullable();
            $table->enum('logout_reason', ['USER', 'LOCK', 'SHUTDOWN', 'TIMEOUT'])->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'login_at']);
            $table->index(['device_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_login_sessions');
    }
};
