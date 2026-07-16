<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single registry of every binary in the object store. Screenshot/webcam log rows
 * point here; the bytes live on disk/MinIO/S3, never in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('file_type', ['SCREENSHOT', 'WEBCAM_PHOTO', 'EXPORT', 'OTHER'])->default('OTHER');
            $table->string('storage_driver')->default('local');
            $table->string('bucket')->nullable();
            $table->string('storage_key');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'file_type']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_files');
    }
};
