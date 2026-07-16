<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('policy_type', [
                'MONITORING', 'SCREENSHOT', 'WEBCAM', 'APPLICATION', 'WEBSITE',
                'NETWORK', 'DEVICE', 'USB', 'VPN_PROXY', 'BREAK', 'ATTENDANCE', 'COMPLIANCE',
            ]);
            $table->unsignedBigInteger('policy_id');
            $table->enum('assignable_type', ['COMPANY', 'BRANCH', 'DEPARTMENT', 'TEAM', 'EMPLOYEE', 'DEVICE']);
            $table->unsignedBigInteger('assignable_id');
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'policy_type']);
            $table->index(['assignable_type', 'assignable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_assignments');
    }
};
