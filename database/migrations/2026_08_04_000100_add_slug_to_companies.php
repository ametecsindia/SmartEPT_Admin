<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client branded console URLs: admin.smartept.com/<slug>.
 * Each company gets a unique, path-safe slug. Nullable so pre-existing
 * companies keep working until provisioning (or an admin) fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'slug')) {
                $table->string('slug', 40)->nullable()->unique()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'slug')) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            }
        });
    }
};
