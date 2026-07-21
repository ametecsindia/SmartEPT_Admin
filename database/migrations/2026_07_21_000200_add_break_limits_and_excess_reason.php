<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 1 & 3 — break categories + limits + excess-break reason.
 * Break categories are Lunch / Tea / Other (Other == the existing break_type CUSTOM,
 * renamed in the UI; Meeting is handled separately and is NOT a break). Per-company
 * permitted durations live on the companies row (tenant-specific, like retention/gate),
 * and each break session records the permitted vs actual, the excess, and — when the
 * limit is exceeded — the mandatory reason plus optional reviewer fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('break_limit_lunch_min')->default(30)->after('exclude_ip_sites');
            $table->unsignedSmallInteger('break_limit_tea_min')->default(10)->after('break_limit_lunch_min');
            $table->unsignedSmallInteger('break_limit_other_min')->default(10)->after('break_limit_tea_min');
        });

        Schema::table('employee_break_logs', function (Blueprint $table) {
            $table->unsignedInteger('permitted_seconds')->nullable()->after('duration_seconds');
            $table->unsignedInteger('excess_seconds')->nullable()->after('permitted_seconds');
            $table->text('delay_reason')->nullable()->after('excess_seconds');
            $table->text('reviewer_remarks')->nullable()->after('delay_reason');
            $table->enum('review_status', ['NONE', 'PENDING', 'REVIEWED'])->default('NONE')->after('reviewer_remarks');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['break_limit_lunch_min', 'break_limit_tea_min', 'break_limit_other_min']);
        });
        Schema::table('employee_break_logs', function (Blueprint $table) {
            $table->dropColumn([
                'permitted_seconds', 'excess_seconds', 'delay_reason',
                'reviewer_remarks', 'review_status', 'reviewed_by_user_id', 'reviewed_at',
            ]);
        });
    }
};
