<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_presence_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->enum('event_type', [
                'PRESENT', 'AWAY_FROM_SCREEN', 'CAMERA_BLOCKED',
                'MULTIPLE_FACE_DETECTED', 'CAMERA_UNAVAILABLE', 'UNKNOWN',
            ])->default('UNKNOWN');
            $table->decimal('confidence_score', 4, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->default(0);
            $table->json('metadata')->nullable(); // face_count, brightness, idle_seconds — NEVER an image
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_presence_events');
    }
};
