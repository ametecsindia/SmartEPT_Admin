<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /** Permission catalogue: slug => [name, group]. */
    private array $permissions = [
        'dashboard.view'     => ['View dashboard', 'Dashboard'],
        'screenshot.view'    => ['View screenshots', 'Monitoring'],
        'webcam.view'        => ['View webcam presence/photos', 'Monitoring'],
        'activity.view'      => ['View activity reports', 'Reports'],
        'attendance.view'    => ['View attendance reports', 'Reports'],
        'employee.edit'      => ['Edit employee data', 'People'],
        'policy.view'        => ['View policies', 'Policies'],
        'policy.edit'        => ['Create/edit policies', 'Policies'],
        'policy.assign'      => ['Assign policies', 'Policies'],
        'export.data'        => ['Export reports/data', 'Reports'],
        'api.manage'         => ['Manage API clients/tokens', 'Administration'],
        'integration.manage' => ['Manage integrations', 'Administration'],
        'audit.view'         => ['View audit logs', 'Administration'],
    ];

    /** Role catalogue: slug => [name, [permission slugs]]. '*' = all permissions. */
    private array $roles = [
        'SUPER_ADMIN'        => ['Super Admin', ['*']],
        'COMPANY_ADMIN'      => ['Company Admin', ['*']],
        'BRANCH_ADMIN'       => ['Branch Admin', ['dashboard.view', 'screenshot.view', 'activity.view', 'attendance.view', 'employee.edit', 'export.data']],
        'MANAGER'            => ['Manager', ['dashboard.view', 'screenshot.view', 'webcam.view', 'activity.view', 'attendance.view', 'export.data']],
        'TEAM_LEADER'        => ['Team Leader', ['dashboard.view', 'screenshot.view', 'activity.view', 'attendance.view']],
        'HR_ADMIN'           => ['HR / Admin', ['dashboard.view', 'activity.view', 'attendance.view', 'employee.edit', 'export.data']],
        'COMPLIANCE_OFFICER' => ['Compliance Officer', ['dashboard.view', 'screenshot.view', 'webcam.view', 'policy.view', 'policy.edit', 'policy.assign', 'audit.view', 'export.data']],
        'AUDITOR'            => ['Auditor', ['dashboard.view', 'screenshot.view', 'webcam.view', 'activity.view', 'attendance.view', 'policy.view', 'audit.view', 'export.data']],
        'EMPLOYEE'           => ['Employee', []],
    ];

    public function run(): void
    {
        $permById = [];
        foreach ($this->permissions as $slug => [$name, $group]) {
            $permById[$slug] = Permission::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group]
            );
        }

        foreach ($this->roles as $slug => [$name, $perms]) {
            $role = Role::updateOrCreate(
                ['company_id' => null, 'slug' => $slug],
                ['name' => $name, 'is_system' => true]
            );

            $ids = $perms === ['*']
                ? collect($permById)->pluck('id')->all()
                : collect($perms)->map(fn ($s) => $permById[$s]->id)->all();

            $role->permissions()->sync($ids);
        }
    }
}
