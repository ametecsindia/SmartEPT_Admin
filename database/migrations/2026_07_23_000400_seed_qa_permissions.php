<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * QA Phase 4 (B5) — granular permission catalogue + sensible role defaults.
 *
 * Idempotent: permissions are firstOrCreate'd on slug and attached to existing
 * SYSTEM roles with syncWithoutDetaching (never wipes a tuned matrix or a custom
 * role). On a FRESH install / test DB the roles don't exist yet at migrate time —
 * RolePermissionSeeder is the source of truth there and carries the same defaults;
 * this migration is what upgrades an EXISTING live database.
 */
return new class extends Migration
{
    /** New permission slugs => [name, group]. attendance.view already exists (kept). */
    private array $catalogue = [
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

    public function up(): void
    {
        $ids = [];
        foreach ($this->catalogue as $slug => [$name, $group]) {
            $ids[$slug] = Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group])->id;
        }

        $all = array_keys($this->catalogue);
        $defaults = [
            // Admin + HR get the whole new set. Super/Company Admin bypass checks too,
            // but attaching keeps permissionSlugs() honest and the matrix accurate.
            'SUPER_ADMIN'        => $all,
            'COMPANY_ADMIN'      => $all,
            'HR_ADMIN'           => $all,
            // Managers / TLs may run meetings but not cancel or manage participants at will.
            'MANAGER'            => ['meeting.view', 'meeting.schedule', 'meeting.edit'],
            'TEAM_LEADER'        => ['meeting.view', 'meeting.schedule', 'meeting.edit'],
            // Auditor: read-only oversight + exports.
            'AUDITOR'            => ['meeting.view', 'meeting.reports', 'audit.export', 'evidence.view'],
            'COMPLIANCE_OFFICER' => ['evidence.view', 'audit.export'],
        ];

        foreach ($defaults as $slug => $grant) {
            $attach = array_values(array_filter(array_map(fn ($s) => $ids[$s] ?? null, $grant)));
            if (! $attach) {
                continue;
            }
            Role::whereNull('company_id')->where('slug', $slug)->get()
                ->each(fn ($role) => $role->permissions()->syncWithoutDetaching($attach));
        }
    }

    public function down(): void
    {
        // FK role_permission cascades on permission delete, so this cleanly removes the grants.
        Permission::whereIn('slug', array_keys($this->catalogue))->delete();
    }
};
