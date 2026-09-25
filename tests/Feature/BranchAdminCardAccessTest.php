<?php
namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\HierarchyService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** 25-Sep-2026: custom role based on Branch Admin — Rules view via card tick, branch scope via employee row. */
class BranchAdminCardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_view_opens_rules_read_and_branch_falls_back_to_employee(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail();
        $role = Role::create(['company_id' => $admin->company_id, 'slug' => 'DEMO_T', 'name' => 'Demo', 'base_slug' => 'BRANCH_ADMIN', 'is_system' => false]);
        $u = User::create(['name' => 'Demo', 'email' => 'demo-t@x.io', 'password' => 'password', 'company_id' => $admin->company_id, 'role_id' => $role->id, 'status' => 'ACTIVE']);
        $this->withToken($u->createToken('t')->plainTextToken);

        // No card tick -> still refused.
        $this->getJson('/api/policies/application')->assertStatus(403);

        $role->permissions()->attach(Permission::firstOrCreate(['slug' => 'card.rules.app_web_rules.view'], ['name' => 'r', 'group' => 'g'])->id);
        $u->unsetRelation('role');
        $this->getJson('/api/policies/application')->assertOk();
        $this->getJson('/api/policies/website')->assertOk();
        $this->getJson('/api/enforcement/audit-report')->assertStatus(200);
        // Policies screen: the Policies list card opens every policy type.
        $this->getJson('/api/policies/screenshot')->assertStatus(403);
        $role->permissions()->attach(Permission::firstOrCreate(['slug' => 'card.policies.policy_list.view'], ['name' => 'p', 'group' => 'g'])->id);
        $this->getJson('/api/policies/screenshot')->assertOk();
        // Writes stay on the role list even with the tick.
        $this->postJson('/api/policies/application', ['name' => 'x'])->assertStatus(403);

        // Branch scope: users.branch_id empty -> linked employee's branch.
        $emps = Employee::withoutGlobalScopes()->where('company_id', $admin->company_id)->whereNotNull('branch_id')->take(2)->get();
        $this->assertCount(2, $emps, 'fixture needs two employees with a branch');
        Employee::withoutGlobalScopes()->whereKey($emps[1]->id)->update(['branch_id' => $emps[0]->branch_id]);
        Employee::withoutGlobalScopes()->whereKey($emps[0]->id)->update(['user_id' => $u->id]);
        $ids = app(HierarchyService::class)->visibleEmployeeIds($u->fresh());
        $this->assertContains($emps[0]->id, $ids);
        $this->assertContains($emps[1]->id, $ids);
    }

    public function test_employee_portal_role_never_gets_card_reads(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail();
        $role = Role::create(['company_id' => $admin->company_id, 'slug' => 'EMP_T', 'name' => 'Emp', 'base_slug' => 'EMPLOYEE', 'is_system' => false]);
        $role->permissions()->attach(Permission::firstOrCreate(['slug' => 'card.org.org_roles.view'], ['name' => 'r', 'group' => 'g'])->id);
        $u = User::create(['name' => 'E', 'email' => 'emp-t@x.io', 'password' => 'password', 'company_id' => $admin->company_id, 'role_id' => $role->id, 'status' => 'ACTIVE']);
        $this->withToken($u->createToken('t')->plainTextToken);
        $this->getJson('/api/roles')->assertStatus(403);
    }
}
