<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_idle_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->timestamp('idle_start')->nullable();
            $table->timestamp('idle_end')->nullable();
            $table->unsignedBigInteger('duration_seconds')->default(0);
            $table->enum('reason', ['NO_INPUT', 'LOCKED', 'AWAY'])->default('NO_INPUT');
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'idle_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_idle_logs');
    }
};
