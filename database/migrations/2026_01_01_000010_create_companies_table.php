<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('legal_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('timezone')->default('Asia/Kolkata');
            $table->enum('deployment_model', ['LAN', 'PRIVATE_CLOUD', 'HYBRID', 'AMETECS_SAAS'])->default('LAN');
            $table->enum('storage_driver', ['MINIO', 'S3', 'AZURE', 'GCP', 'NAS', 'LOCAL'])->default('LOCAL');
            $table->json('storage_settings')->nullable();
            $table->unsignedInteger('data_retention_days')->default(90);
            $table->enum('status', ['ACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
