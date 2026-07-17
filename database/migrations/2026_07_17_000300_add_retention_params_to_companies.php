<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ejaz 17-Jul: give each company fine-grained AUTO storage-cleanup parameters
 * (not just one blanket retention). The nightly smartept:purge-expired uses
 * these; null on any column falls back to companies.data_retention_days.
 * Screenshots are the disk hog, so they get their own knob, and violation
 * evidence can be kept longer than routine captures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('auto_cleanup_enabled')->default(true)->after('data_retention_days');
            $t->unsignedInteger('retention_screenshots_days')->nullable()->after('auto_cleanup_enabled');
            $t->unsignedInteger('retention_activity_days')->nullable()->after('retention_screenshots_days');
            $t->unsignedInteger('retention_usage_days')->nullable()->after('retention_activity_days');
            $t->unsignedInteger('retention_violation_days')->nullable()->after('retention_usage_days')
                ->comment('Keep violation screenshots + compliance events this long (null = same as data_retention_days)');
            $t->boolean('retention_keep_violation_evidence')->default(true)->after('retention_violation_days');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn([
            'auto_cleanup_enabled', 'retention_screenshots_days', 'retention_activity_days',
            'retention_usage_days', 'retention_violation_days', 'retention_keep_violation_evidence',
        ]));
    }
};
