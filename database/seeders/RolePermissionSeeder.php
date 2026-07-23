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
        // QA Phase 4 (B5): granular permissions. Kept in step with migration
        // 2026_07_23_000400_seed_qa_permissions (that one upgrades existing live DBs).
        'meeting.view'          => ['View meetings', 'Meetings'],
        'meeting.schedule'      => ['Schedule meetings', 'Meetings'],
        'meeting.edit'          => ['Edit / reschedule meetings', 'Meetings'],
        'meeting.cancel'        => ['Cancel meetings', 'Meetings'],
        'meeting.participants'  => ['Manage meeting participants', 'Meetings'],
        'meeting.reports'       => ['View meeting participation & reports', 'Meetings'],
        'biometric.sync.config' => ['Configure biometric sync devices', 'Biometric'],
        'biometric.sync.run'    => ['Run biometric sync now', 'Biometric'],
        'attendance.edit'       => ['Edit / regularize attendance', 'Reports'],
        'audit.export'          => ['Export audit logs', 'Administration'],
        'evidence.view'         => ['View evidence (screenshots / webcam)', 'Monitoring'],
        'gate.override'         => ['Override the biometric gate', 'Biometric'],
        'agent.maintenance'     => ['Manage agent maintenance / exit lock', 'Administration'],
        'agent.tamper.manage'   => ['Review & manage agent tamper events', 'Administration'],
    ];

    /** Role catalogue: slug => [name, [permission slugs]]. '*' = all permissions. */
    private array $roles = [
        'SUPER_ADMIN'        => ['Super Admin', ['*']],
        'COMPANY_ADMIN'      => ['Company Admin', ['*']],
        'BRANCH_ADMIN'       => ['Branch Admin', ['dashboard.view', 'screenshot.view', 'activity.view', 'attendance.view', 'employee.edit', 'export.data']],
        'MANAGER'            => ['Manager', ['dashboard.view', 'screenshot.view', 'webcam.view', 'activity.view', 'attendance.view', 'export.data', 'meeting.view', 'meeting.schedule', 'meeting.edit']],
        'TEAM_LEADER'        => ['Team Leader', ['dashboard.view', 'screenshot.view', 'activity.view', 'attendance.view', 'meeting.view', 'meeting.schedule', 'meeting.edit']],
        'HR_ADMIN'           => ['HR / Admin', ['dashboard.view', 'activity.view', 'attendance.view', 'employee.edit', 'export.data', 'meeting.view', 'meeting.schedule', 'meeting.edit', 'meeting.cancel', 'meeting.participants', 'meeting.reports', 'biometric.sync.config', 'biometric.sync.run', 'attendance.edit', 'audit.export', 'evidence.view', 'gate.override', 'agent.maintenance', 'agent.tamper.manage']],
        'COMPLIANCE_OFFICER' => ['Compliance Officer', ['dashboard.view', 'screenshot.view', 'webcam.view', 'policy.view', 'policy.edit', 'policy.assign', 'audit.view', 'export.data', 'evidence.view', 'audit.export']],
        'AUDITOR'            => ['Auditor', ['dashboard.view', 'screenshot.view', 'webcam.view', 'activity.view', 'attendance.view', 'policy.view', 'audit.view', 'export.data', 'meeting.view', 'meeting.reports', 'audit.export', 'evidence.view']],
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
