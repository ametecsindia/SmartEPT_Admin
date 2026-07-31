<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Employee Self-Service portal: give the built-in EMPLOYEE role its read-only
 * view permissions so a logged-in employee can open their 7 approved tabs.
 * View-only — no create/edit/assign/export/webcam/admin permissions.
 * Data scoping to the employee's OWN records is enforced separately in
 * HierarchyService (an EMPLOYEE only ever sees their own row), so these
 * permissions unlock the pages without ever exposing another employee's data.
 * Only the system EMPLOYEE role (company_id NULL) is touched; company custom
 * roles and every other role are left exactly as they are.
 */
return new class extends Migration
{
    private array $slugs = ['dashboard.view', 'attendance.view', 'screenshot.view', 'activity.view', 'policy.view'];

    public function up(): void
    {
        $role = Role::query()->whereNull('company_id')->where('slug', 'EMPLOYEE')->first();
        if (! $role) {
            return; // no seeded EMPLOYEE role on this install — nothing to do
        }

        $ids = Permission::query()->whereIn('slug', $this->slugs)->pluck('id')->all();
        // Set the EMPLOYEE role to EXACTLY these 5 view permissions (view-only baseline).
        $role->permissions()->sync($ids);
    }

    public function down(): void
    {
        $role = Role::query()->whereNull('company_id')->where('slug', 'EMPLOYEE')->first();
        if ($role) {
            $role->permissions()->sync([]); // back to the original empty EMPLOYEE role
        }
    }
};
