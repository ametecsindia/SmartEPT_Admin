<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 30-Sep-2026 (Ejaz): Reports → Schedule Report. An admin schedules the Productivity report
 * to be emailed automatically — every employee their own day, and/or the full report to
 * chosen people and extra addresses. Creating and switching on a schedule IS the approval
 * for those emails (nothing is sent until an admin saves one).
 *
 * Also adds the "Report schedules" card to the role matrix (Organisation → Roles):
 * View follows whatever a role already sees on Reports & Exports; Edit is for Super/Company
 * Admin until someone ticks it for another role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_schedules')) {
            Schema::create('report_schedules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->index();
                $t->foreignId('created_by')->nullable();          // the admin whose scope bounds the data
                $t->string('name', 120);
                $t->boolean('enabled')->default(true);
                $t->string('frequency', 10)->default('DAILY');    // DAILY | WEEKLY | MONTHLY
                $t->string('send_time', 5)->default('09:00');     // HH:MM, company clock
                $t->json('days')->nullable();                     // ISO weekdays 1..7 (DAILY: which days, WEEKLY: [the day])
                $t->unsignedTinyInteger('day_of_month')->default(1); // MONTHLY: 1..28
                $t->json('scope')->nullable();                    // {branch_id, department_id, team_id} of the full report
                $t->boolean('all_employees_own')->default(false); // every active employee in scope gets their own report
                $t->json('recipients')->nullable();               // [{employee_id, email|null, mode OWN|FULL}]
                $t->json('extra_emails')->nullable();             // extra addresses → full report
                $t->boolean('skip_empty')->default(true);         // no activity → no email
                $t->boolean('skip_holidays')->default(true);      // report day was a company holiday → no email
                $t->boolean('attach_excel')->default(true);
                $t->string('subject', 200)->nullable();           // blank = built-in
                $t->text('message')->nullable();                  // note shown on top of every email
                $t->timestamp('last_run_at')->nullable();
                $t->string('last_period', 20)->nullable();        // company-local send date already done
                $t->string('last_status', 500)->nullable();
                $t->timestamps();
            });
        }

        $view = Permission::updateOrCreate(['slug' => 'card.schedrep.report_schedules.view'],
            ['name' => 'Report schedules', 'group' => 'Card access · Schedule Report'])->id;
        $edit = Permission::updateOrCreate(['slug' => 'card.schedrep.report_schedules.edit'],
            ['name' => 'Report schedules (edit)', 'group' => 'Card access · Schedule Report'])->id;

        foreach (Role::with('permissions:id,slug')->get() as $role) {
            if (in_array($role->slug, ['SUPER_ADMIN', 'COMPANY_ADMIN'], true)) {
                $role->permissions()->syncWithoutDetaching([$view, $edit]);
                continue;
            }
            $held = $role->permissions->pluck('slug')->all();
            if (preg_grep('/^card\.reports\./', $held)) {
                $role->permissions()->syncWithoutDetaching([$view]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
        Permission::whereIn('slug', ['card.schedrep.report_schedules.view', 'card.schedrep.report_schedules.edit'])->delete();
    }
};
