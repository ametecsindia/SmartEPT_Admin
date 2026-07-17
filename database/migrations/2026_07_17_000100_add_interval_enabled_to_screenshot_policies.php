<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ejaz 17-Jul: screenshot policies get a capture-mode switch — timed interval
 * captures ON (everything) or OFF (violations-only). Violation/blocked-app/
 * blocked-site captures keep their own existing toggles either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('screenshot_policies', 'interval_enabled')) {
            Schema::table('screenshot_policies', function (Blueprint $t) {
                $t->boolean('interval_enabled')->default(true)->after('interval_seconds')
                    ->comment('OFF = no timed/random captures; only violation-triggered screenshots');
            });
        }
    }

    public function down(): void
    {
        Schema::table('screenshot_policies', fn (Blueprint $t) => $t->dropColumn('interval_enabled'));
    }
};
