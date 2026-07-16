<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->enum('event_type', ['ACTIVE', 'IDLE'])->default('ACTIVE');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->default(0);
            $table->string('active_app')->nullable();
            $table->string('window_title', 512)->nullable();
            $table->boolean('keyboard_activity')->default(false);
            $table->boolean('mouse_activity')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_activity_events');
    }
};
