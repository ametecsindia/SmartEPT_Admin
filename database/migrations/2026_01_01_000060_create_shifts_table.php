<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->time('start_time')->default('09:00:00');
            $table->time('end_time')->default('18:00:00');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedInteger('grace_minutes')->default(10);
            $table->json('working_days')->nullable(); // e.g. ["MON","TUE","WED","THU","FRI"]
            $table->unsignedInteger('break_minutes_allowed')->default(60);
            $table->string('timezone')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
