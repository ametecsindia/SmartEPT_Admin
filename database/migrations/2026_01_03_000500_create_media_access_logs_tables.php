<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accountability trail: every view/download of a screenshot or webcam photo is recorded,
 * so the watchers are themselves auditable. Excluded from the ordinary retention purge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshot_access_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('employee_screenshot_log_id');
            $t->unsignedBigInteger('employee_id');
            $t->string('ip')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'employee_id']);
        });

        Schema::create('webcam_photo_access_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('employee_webcam_log_id');
            $t->unsignedBigInteger('employee_id');
            $t->string('ip')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webcam_photo_access_logs');
        Schema::dropIfExists('screenshot_access_logs');
    }
};
