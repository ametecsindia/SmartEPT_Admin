<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 09-Oct-2026 (Ejaz): "settings in one place, not multiple places which creates ambiguity and
 * errors in reports". The SHIFT becomes the only home for every time rule:
 *
 *   late grace           shifts.grace_minutes            (Attendance policy "Late grace" removed)
 *   auto sign-out        shifts.post_shift_auto_logout_minutes (Attendance policy fallback removed)
 *   half-day minimum     shifts.min_working_hours        NEW (was Attendance policy min_working_hours)
 *   break limits         shifts.break_lunch/tea/other_min NEW (was Organisation → Break limits;
 *                                                         the report's allotted break = their sum)
 *
 * Today's effective values are COPIED onto every shift so nothing changes on upgrade:
 *   - the company-level Attendance policy's late grace (it overrode the shift) is written onto
 *     every shift; its auto sign-out + min working hours fill shifts that
 *     have none of their own (the old fallback, made explicit);
 *   - the company's Lunch / Tea / Other break limits are written onto each of its shifts.
 * Old columns are left in place (data only, unread) so a rollback loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('shifts', 'break_lunch_min')) {
                $table->unsignedSmallInteger('break_lunch_min')->nullable()->after('break_minutes_allowed');
                $table->unsignedSmallInteger('break_tea_min')->nullable()->after('break_lunch_min');
                $table->unsignedSmallInteger('break_other_min')->nullable()->after('break_tea_min');
            }
            if (! Schema::hasColumn('shifts', 'min_working_hours')) {
                $table->decimal('min_working_hours', 4, 2)->nullable()->after('break_other_min');
            }
        });

        foreach (DB::table('companies')->get() as $c) {
            // Break limits: Organisation → each shift of that company.
            DB::table('shifts')->where('company_id', $c->id)->update([
                'break_lunch_min' => $c->break_limit_lunch_min ?? null,
                'break_tea_min'   => $c->break_limit_tea_min ?? null,
                'break_other_min' => $c->break_limit_other_min ?? null,
            ]);

            // The company-level Attendance policy (latest assignment wins, as PolicyResolver did).
            $policyId = DB::table('policy_assignments')
                ->where('company_id', $c->id)->where('policy_type', 'ATTENDANCE')
                ->where('assignable_type', 'COMPANY')->where('assignable_id', $c->id)
                ->orderByDesc('id')->value('policy_id');
            $policy = $policyId ? DB::table('attendance_policies')->where('id', $policyId)->first() : null;
            if (! $policy) {
                continue;
            }

            // Late grace: the policy value was the one in force (it overrode the shift), so it
            // becomes the shift's grace — reports keep the cut-off they had the day before.
            if (($policy->late_grace_minutes ?? null) !== null) {
                DB::table('shifts')->where('company_id', $c->id)
                    ->update(['grace_minutes' => $policy->late_grace_minutes]);
            }
            if (($policy->post_shift_auto_logout_minutes ?? null) !== null) {
                DB::table('shifts')->where('company_id', $c->id)->whereNull('post_shift_auto_logout_minutes')
                    ->update(['post_shift_auto_logout_minutes' => $policy->post_shift_auto_logout_minutes]);
            }
            if ((float) ($policy->min_working_hours ?? 0) > 0) {
                DB::table('shifts')->where('company_id', $c->id)->whereNull('min_working_hours')
                    ->update(['min_working_hours' => $policy->min_working_hours]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['break_lunch_min', 'break_tea_min', 'break_other_min', 'min_working_hours']);
        });
    }
};
