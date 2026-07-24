<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Archive (Ejaz 24-Jul): when an employee is DELETED we keep a complete,
 * retrievable backup of everything that belonged to them — profile, attendance,
 * breaks, screenshots, violations, etc. — while the live employee_code is freed for
 * reuse. One row per archived employee; the heavy row-level export + the actual media
 * files live in a ZIP built in the background (file_status PENDING -> READY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index(); // the (soft-deleted) source row
            $table->string('archive_label');            // Code_Name_YYYY-MM-DD (display id)
            $table->string('original_employee_code');   // the code that was freed
            $table->string('employee_name');
            $table->unsignedBigInteger('archived_by_user_id')->nullable();
            $table->timestamp('archived_at');

            // Compact DB snapshot: profile + per-table counts + date ranges (the full
            // row-level data + media files are in the ZIP).
            $table->json('snapshot')->nullable();
            $table->json('counts')->nullable();

            // Background ZIP build.
            $table->enum('file_status', ['PENDING', 'READY', 'FAILED'])->default('PENDING')->index();
            $table->string('storage_driver')->nullable();
            $table->string('storage_key')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('media_files')->nullable();
            $table->string('error', 1000)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_archives');
    }
};
