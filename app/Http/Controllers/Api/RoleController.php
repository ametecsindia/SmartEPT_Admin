<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * R4 item 5 — organisation roles + module-permission matrix.
 * System roles (seeded, company_id NULL) can have their permissions tuned but are
 * never renamed/deleted; SUPER_ADMIN and COMPANY_ADMIN stay locked at "everything"
 * so an admin can never lock themselves out. Custom roles belong to the company,
 * inherit route access from their base system role (EnsureRole), and the matrix
 * decides which modules their users see.
 */
class RoleController extends Controller
{
    /** Base roles a custom role may inherit route access from. */
    private const BASES = ['BRANCH_ADMIN', 'MANAGER', 'TEAM_LEADER', 'HR_ADMIN', 'COMPLIANCE_OFFICER', 'AUDITOR', 'EMPLOYEE'];

    /** Roles whose permission set is not editable (full access, lockout guard). */
    private const LOCKED = ['SUPER_ADMIN', 'COMPANY_ADMIN'];

    /** GET /api/roles — all roles visible to this company + the permission catalogue. */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $roles = Role::withCount('users')->with('permissions:id,slug')
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderByDesc('is_system')->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'slug'           => $r->slug,
                'name'           => $r->name,
                'base_slug'      => $r->base_slug,
                'is_system'      => (bool) $r->is_system,
                'locked'         => in_array($r->slug, self::LOCKED, true),
                'users_count'    => $r->users_count,
                'permission_ids' => $r->permissions->pluck('id')->values(),
            ]);

        $permissions = Permission::orderBy('group')->orderBy('name')
            ->get(['id', 'slug', 'name', 'group']);

        return response()->json(['data' => $roles, 'permissions' => $permissions, 'bases' => self::BASES]);
    }

    /** POST /api/roles — create a custom company role. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'base_slug' => ['required', Rule::in(self::BASES)],
        ]);

        $companyId = $request->user()->company_id;
        $slug = Str::upper(Str::slug($data['name'], '_'));
        if ($slug === '' || Role::where('slug', $slug)->exists()) {
            $slug = $slug . '_' . Str::upper(Str::random(4));
        }

        $role = Role::create([
            'company_id' => $companyId,
            'slug'       => $slug,
            'name'       => $data['name'],
            'base_slug'  => $data['base_slug'],
            'is_system'  => false,
        ]);

        // Start from the base role's permission set — tune in the matrix after.
        $base = Role::whereNull('company_id')->where('slug', $data['base_slug'])->first();
        if ($base) {
            $role->permissions()->sync($base->permissions()->pluck('permissions.id')->all());
        }

        $this->audit($request, 'CREATE', Role::class, $role->id, $data);

        return response()->json(['data' => $role->fresh()->loadCount('users')], 201);
    }

    /** PUT /api/roles/{role} — rename / rebase a custom role. */
    public function update(Request $request, Role $role): JsonResponse
    {
        $this->guardRole($request, $role);
        abort_if($role->is_system, 422, 'System roles cannot be renamed.');

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:120'],
            'base_slug' => ['sometimes', Rule::in(self::BASES)],
        ]);

        $role->update($data);
        $this->audit($request, 'UPDATE', Role::class, $role->id, $data);

        return response()->json(['data' => $role->fresh()]);
    }

    /** DELETE /api/roles/{role} — custom roles only, and only when unused. */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->guardRole($request, $role);
        abort_if($role->is_system, 422, 'System roles cannot be deleted.');
        abort_if($role->users()->exists(), 422, 'Reassign the users on this role first.');

        $role->permissions()->detach();
        $this->audit($request, 'DELETE', Role::class, $role->id);
        $role->delete();

        return response()->json(null, 204);
    }

    /** PUT /api/roles/{role}/permissions — save one matrix row. */
    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $this->guardRole($request, $role);
        abort_if(in_array($role->slug, self::LOCKED, true), 422,
            'This role always has full access — that is what keeps you from locking yourself out.');

        $data = $request->validate([
            'permission_ids'   => ['present', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role->permissions()->sync($data['permission_ids']);
        $this->audit($request, 'UPDATE', Role::class, $role->id, ['permissions' => $data['permission_ids']]);

        return response()->json(['ok' => true, 'permission_ids' => $role->permissions()->pluck('permissions.id')->values()]);
    }

    /** A company admin may touch global roles and their own company's roles — never another tenant's. */
    private function guardRole(Request $request, Role $role): void
    {
        $user = $request->user();
        abort_if($role->company_id !== null
            && ! $user->isSuperAdmin()
            && $role->company_id !== $user->company_id, 403, 'Outside your tenant.');
    }
}
