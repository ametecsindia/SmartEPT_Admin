<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends Laravel's default users table with SmartEPT tenancy + role fields,
 * rather than replacing the framework migration (which also creates the
 * sessions and password_reset_tokens tables). This keeps the default DB
 * session/cache/queue drivers working on Laragon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->enum('status', ['ACTIVE', 'DISABLED'])->default('ACTIVE')->after('phone');
            $table->timestamp('last_login_at')->nullable();
            $table->string('two_factor_secret')->nullable();
            $table->index(['company_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['phone', 'status', 'last_login_at', 'two_factor_secret']);
        });
    }
};
