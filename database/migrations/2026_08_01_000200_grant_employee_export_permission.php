<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Employee Self-Service: allow an employee to download (export) reports of THEIR OWN
 * data. ExportController already scopes every export by HierarchyService::visibleEmployeeIds,
 * so an EMPLOYEE only ever exports their own rows. Adds export.data to the seeded EMPLOYEE
 * role WITHOUT removing its 5 view permissions. Audit-log export stays gated on its own
 * audit.export permission, which the EMPLOYEE role does not hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->whereNull('company_id')->where('slug', 'EMPLOYEE')->first();
        $perm = Permission::query()->where('slug', 'export.data')->first();
        if ($role && $perm) {
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }
    }

    public function down(): void
    {
        $role = Role::query()->whereNull('company_id')->where('slug', 'EMPLOYEE')->first();
        $perm = Permission::query()->where('slug', 'export.data')->first();
        if ($role && $perm) {
            $role->permissions()->detach($perm->id);
        }
    }
};
