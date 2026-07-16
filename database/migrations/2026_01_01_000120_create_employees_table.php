<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // optional self-service login

            $table->string('employee_code');
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->enum('employment_status', ['ACTIVE', 'ON_LEAVE', 'RELIEVED'])->default('ACTIVE');
            $table->date('date_of_joining')->nullable();
            $table->date('date_of_relieving')->nullable();

            // Cross-system + policy links (no FK constraint: policies may be assigned later / by precedence)
            $table->string('biometric_id')->nullable();
            $table->string('smartprs_employee_id')->nullable();
            $table->string('smartdcm_user_id')->nullable();
            $table->unsignedBigInteger('monitoring_policy_id')->nullable();
            $table->unsignedBigInteger('compliance_policy_id')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_code']);
            $table->index(['company_id', 'team_id']);
            $table->index(['company_id', 'employment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
