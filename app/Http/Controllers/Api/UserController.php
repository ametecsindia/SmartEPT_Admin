<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\MailService;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Release-1 · User account lifecycle (admin login management).
 *
 * User does NOT use the BelongsToCompany global scope (it would break login and
 * Super Admin provisioning), so every method here scopes/guards by company_id
 * manually: listings filter by the caller's company and single-user routes 404
 * on cross-tenant access, exactly as if the row did not exist.
 */
class UserController extends Controller
{
    use ScopesVisibleEmployees;

    /** User ids the caller may manage: unrestricted -> null; else logins linked to visible employees + self. */
    private function visibleUserIds(User $caller): ?array
    {
        $emps = $this->visibleEmployeeIds($caller);
        if ($emps === null) {
            return null;
        }
        $ids = Employee::whereIn('id', $emps)->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->all();
        $ids[] = (int) $caller->id;

        return array_values(array_unique($ids));
    }
    /** GET /api/users — paginated, filter ?q= (name/email) and ?role=slug. */
    public function index(Request $request): JsonResponse
    {
        $caller = $request->user();

        $users = User::query()
            ->with(['role:id,name,slug', 'employee:id,user_id,employee_code,first_name,last_name'])
            // Manual tenant scope: Super Admin sees every tenant, everyone else only their own.
            ->when(! $caller->isSuperAdmin(), fn ($q) => $q->where('company_id', $caller->company_id))
            ->when(($__vis = $this->visibleUserIds($caller)) !== null, fn ($q) => $q->whereIn('id', $__vis))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) =>
                $w->where('name', 'like', "%{$v}%")
                  ->orWhere('email', 'like', "%{$v}%")))
            ->when($request->role, fn ($q, $slug) => $q->whereHas('role', fn ($r) => $r->where('slug', $slug)))
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 25))
            ->through(fn (User $u) => [
                'id'                   => $u->id,
                'name'                 => $u->name,
                'email'                => $u->email,
                'company_id'           => $u->company_id,
                'status'               => $u->status,
                'must_change_password' => (bool) $u->must_change_password,
                'role'                 => $u->role?->slug,
                'role_name'            => $u->role?->name,
                'employee'             => $u->employee ? [
                    'id'   => $u->employee->id,
                    'code' => $u->employee->employee_code,
                    'name' => $u->employee->fullName(),
                ] : null,
                'last_login_at'        => $u->last_login_at?->toDateTimeString(),
            ]);

        return response()->json($users);
    }

    /** POST /api/users — provision a login with a one-time temporary password. */
    public function store(Request $request): JsonResponse
    {
        $caller = $request->user();
        // New accounts always land in the caller's tenant; only Super Admin
        // (who has no company) may target an explicit company_id.
        $companyId = $caller->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $caller->company_id;

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'email', 'max:255', 'unique:users,email'],
            'role'        => ['required', 'string', Rule::exists('roles', 'slug')],
            'company_id'  => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            // Optional link to an employee record: must belong to the same tenant
            // and must not already have a login (one login per employee).
            'employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('user_id')->whereNull('deleted_at')),
            ],
        ]);

        $role = $this->resolveRole($caller, $data['role']);

        // Ejaz, 14-Aug-2026 (finding 1.4): one licensed seat = one monitored
        // person. An EMPLOYEE login is a monitored person, so it needs a free
        // seat. Admin / HR / Manager logins operate the system and stay free.
        if ($role->slug === 'EMPLOYEE'
            && ($why = app(\App\Services\LicenceSeats::class)->blockedReason($companyId, 'user'))) {
            return response()->json([
                'error' => ['code' => 'LICENSE_SEAT_LIMIT', 'message' => $why],
            ], 409);
        }

        // Strong 10-char temporary password (upper/lower/digits/symbols). It is
        // returned exactly once in this response — only the hash is stored.
        $temp = Str::password(10);

        $user = User::create([
            'name'                 => $data['name'],
            'email'                => $data['email'],
            'password'             => $temp, // hashed by the model cast
            'company_id'           => $companyId,
            'role_id'              => $role->id,
            'status'               => 'ACTIVE',
            'must_change_password' => true,
        ]);

        if (! empty($data['employee_id'])) {
            Employee::withoutGlobalScope('company')->whereKey($data['employee_id'])->update(['user_id' => $user->id]);
        }

        // Mail is best-effort: never blocks the API (see MailService).
        MailService::sendCredentials($user, $temp);

        $this->audit($request, 'CREATE', User::class, $user->id, [
            'email' => $user->email, 'role' => $role->slug, 'employee_id' => $data['employee_id'] ?? null,
        ]);

        return response()->json([
            'data'          => $this->payload($user->fresh(['role', 'employee'])),
            'temp_password' => $temp,
        ], 201);
    }

    /** PUT /api/users/{user} — name / email / role / status. */
    public function update(Request $request, User $user): JsonResponse
    {
        $caller = $request->user();
        $this->guardTenant($caller, $user);

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'email'  => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'   => ['sometimes', 'string', Rule::exists('roles', 'slug')],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'DISABLED'])],
        ]);

        // Self-lockout guards: an admin must not disable their own account or
        // change their own role (privilege changes need a second admin).
        if ($user->id === $caller->id) {
            if (($data['status'] ?? null) === 'DISABLED') {
                throw ValidationException::withMessages(['status' => ['You cannot disable your own account.']]);
            }
            if (isset($data['role']) && $data['role'] !== $user->roleSlug()) {
                throw ValidationException::withMessages(['role' => ['You cannot change your own role.']]);
            }
        }

        if (isset($data['role'])) {
            $data['role_id'] = $this->resolveRole($caller, $data['role'])->id;
            unset($data['role']);
        }

        $user->update($data);

        // Disabling must take effect immediately, not at next login — kill any
        // tokens the account still holds.
        if (($data['status'] ?? null) === 'DISABLED') {
            $user->tokens()->delete();
        }

        $this->audit($request, 'UPDATE', User::class, $user->id, $data);

        return response()->json(['data' => $this->payload($user->fresh(['role', 'employee']))]);
    }

    /**
     * POST /api/users/{user}/reset-password — new temp password, all sessions revoked.
     * Accepts an optional custom password (R4 item 1) — when the admin edits the
     * generated suggestion in the console, that exact password is applied instead.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->guardTenant($request->user(), $user);
        $vis = $this->visibleUserIds($request->user());
        abort_unless($vis === null || in_array($user->id, $vis, true), 403, 'That user is not in your team.');

        $data = $request->validate(['password' => ['nullable', 'string', 'min:8', 'max:72']]);
        $temp = $data['password'] ?? Str::password(10);
        $user->forceFill(['password' => $temp, 'must_change_password' => true])->save();

        // A reset means the old credential can no longer be trusted — revoke
        // every existing token so stale devices are forced to re-authenticate.
        $user->tokens()->delete();

        MailService::sendCredentials($user, $temp);
        $this->audit($request, 'RESET_PASSWORD', User::class, $user->id);

        return response()->json([
            'data'          => $this->payload($user->fresh(['role', 'employee'])),
            'temp_password' => $temp,
        ]);
    }

    /** DELETE /api/users/{user} — soft-disable (no hard delete: audit rows reference users). */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $caller = $request->user();
        $this->guardTenant($caller, $user);

        if ($user->id === $caller->id) {
            throw ValidationException::withMessages(['user' => ['You cannot disable your own account.']]);
        }

        $user->update(['status' => 'DISABLED']);
        $user->tokens()->delete();
        $this->audit($request, 'DELETE', User::class, $user->id, ['status' => 'DISABLED']);

        return response()->json(null, 204);
    }

    /**
     * Cross-tenant requests 404 (not 403) so other tenants' user ids are not
     * even confirmed to exist. Super Admin crosses tenant boundaries freely.
     */
    private function guardTenant(User $caller, User $target): void
    {
        if (! $caller->isSuperAdmin() && $target->company_id !== $caller->company_id) {
            abort(404);
        }
    }

    /**
     * Map a role slug to the system role row. SUPER_ADMIN is the one role a
     * tenant admin can never hand out — that would be privilege escalation.
     */
    private function resolveRole(User $caller, string $slug): Role
    {
        if ($slug === 'SUPER_ADMIN' && ! $caller->isSuperAdmin()) {
            throw ValidationException::withMessages(['role' => ['Only a Super Admin may assign the SUPER_ADMIN role.']]);
        }

        return Role::where('slug', $slug)->orderByRaw('company_id IS NOT NULL')->firstOrFail();
    }

    private function payload(User $user): array
    {
        return [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'company_id'           => $user->company_id,
            'status'               => $user->status,
            'must_change_password' => (bool) $user->must_change_password,
            'role'                 => $user->role?->slug,
            'role_name'            => $user->role?->name,
            'employee'             => $user->employee ? [
                'id'   => $user->employee->id,
                'code' => $user->employee->employee_code,
                'name' => $user->employee->fullName(),
            ] : null,
        ];
    }
}
