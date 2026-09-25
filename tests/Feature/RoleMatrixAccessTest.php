<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 25-Sep-2026: the role matrix's View/Edit ticks are what the server enforces. */
class RoleMatrixAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail();
    }

    /** Custom role on $base holding exactly $perms; returns a logged-in user. */
    private function as(string $base, array $perms): User
    {
        static $n = 0; $n++;
        $role = Role::create(['company_id' => $this->admin->company_id, 'slug' => "T_$n", 'name' => "T$n", 'base_slug' => $base, 'is_system' => false]);
        $role->permissions()->sync(collect($perms)->map(fn ($s) => Permission::firstOrCreate(['slug' => $s], ['name' => $s, 'group' => 'g'])->id)->all());
        $u = User::create(['name' => "U$n", 'email' => "u$n@t.io", 'password' => 'password', 'company_id' => $this->admin->company_id, 'role_id' => $role->id, 'status' => 'ACTIVE']);
        $this->withToken($u->createToken('t')->plainTextToken);

        return $u;
    }

    public function test_view_reads_edit_writes(): void
    {
        $this->as('BRANCH_ADMIN', ['card.rules.app_web_rules.view']);
        $this->getJson('/api/policies/application')->assertOk();
        $this->getJson('/api/enforcement/audit-report')->assertOk();
        $this->postJson('/api/policies/application', ['name' => 'x'])->assertStatus(403);
        $this->postJson('/api/enforcement/device-control', ['block_removable_storage' => true])->assertStatus(403);

        $this->as('BRANCH_ADMIN', ['card.rules.app_web_rules.edit']); // Edit implies View
        $this->getJson('/api/policies/website')->assertOk();
        $this->postJson('/api/policies/application', ['name' => 'x'])->assertStatus(201);
    }

    public function test_an_unticked_card_denies_even_what_the_old_role_list_allowed(): void
    {
        // MANAGER was always on GET /attendance; with the card unticked it is refused.
        $this->as('MANAGER', ['card.dashboard.workforce_status.view']);
        $this->getJson('/api/attendance')->assertStatus(403);
        $this->as('MANAGER', ['card.attendance.attendance_sheet.view']);
        $this->getJson('/api/attendance')->assertOk();
    }

    public function test_card_tick_replaces_old_module_permission(): void
    {
        // screenshot.view is not held — the card tick alone opens the screen.
        $this->as('TEAM_LEADER', ['card.screenshots.screenshot_timeline.view']);
        $this->getJson('/api/reports/screenshots')->assertOk();
    }

    public function test_users_edit_cannot_create_or_take_over_an_admin(): void
    {
        $this->as('HR_ADMIN', ['card.users.login_accounts.view', 'card.users.login_accounts.edit']);
        $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@t.io', 'role' => 'COMPANY_ADMIN'])->assertStatus(422);
        $this->postJson("/api/users/{$this->admin->id}/reset-password")->assertStatus(403);
    }

    public function test_roles_edit_cannot_grant_more_than_it_holds(): void
    {
        $u = $this->as('BRANCH_ADMIN', ['card.org.org_roles.view', 'card.org.org_roles.edit']);
        $other = Role::create(['company_id' => $u->company_id, 'slug' => 'OTHER_T', 'name' => 'Other', 'base_slug' => 'BRANCH_ADMIN', 'is_system' => false]);
        $licence = Permission::firstOrCreate(['slug' => 'card.license.lic_key.edit'], ['name' => 'k', 'group' => 'g'])->id;
        $this->putJson("/api/roles/{$other->id}/permissions", ['permission_ids' => [$licence]])->assertStatus(403);
        $mine = Permission::where('slug', 'card.org.org_roles.view')->value('id');
        $this->putJson("/api/roles/{$other->id}/permissions", ['permission_ids' => [$mine]])->assertOk();
        $this->putJson("/api/roles/{$u->role_id}/permissions", ['permission_ids' => [$mine]])->assertStatus(403);
    }

    public function test_each_settings_card_edits_only_its_own_fields(): void
    {
        $u = $this->as('BRANCH_ADMIN', ['card.org.company_timezone.edit']);
        $this->putJson("/api/companies/{$u->company_id}", ['timezone' => 'Asia/Kolkata'])->assertOk();
        $this->putJson("/api/companies/{$u->company_id}", ['exclude_ip_sites' => false])->assertStatus(403);
        $this->getJson("/api/companies/{$u->company_id}")->assertOk()->assertJsonMissingPath('data.storage_settings');
    }

    public function test_admin_and_unmapped_routes_unchanged(): void
    {
        $this->withToken($this->admin->createToken('t')->plainTextToken);
        $this->getJson('/api/license')->assertOk();
        // Unmapped operator route still follows the old role list for everyone else.
        $this->as('BRANCH_ADMIN', ['card.ops.audit_trail.view']);
        $this->getJson('/api/ops/backups')->assertStatus(403);
        $this->getJson('/api/audit-logs')->assertOk();
    }

    public function test_new_cards_carry_over_each_roles_current_access(): void
    {
        $mk = function (string $slug, string $base, array $perms) {
            $r = Role::create(['company_id' => $this->admin->company_id, 'slug' => $slug, 'name' => $slug, 'base_slug' => $base, 'is_system' => false]);
            $r->permissions()->sync(Permission::whereIn('slug', $perms)->pluck('id')->all());
            return $r;
        };
        $viewer = $mk('MIG_V', 'BRANCH_ADMIN', ['card.rules.app_web_rules.view']);
        $editor = $mk('MIG_E', 'MANAGER', ['card.rules.app_web_rules.view', 'card.rules.app_web_rules.edit']);
        $legacy = $mk('MIG_L', 'MANAGER', []);

        (require database_path('migrations/2026_09_25_000100_add_missing_card_permissions.php'))->up();

        $slugs = fn (Role $r) => $r->fresh()->permissions()->pluck('slug')->all();
        // Split-out cards inherit the ticks of the card they came from.
        $this->assertContains('card.rules.device_control.view', $slugs($viewer));
        $this->assertNotContains('card.rules.device_control.edit', $slugs($viewer));
        $this->assertContains('card.rules.enforcement.edit', $slugs($editor));
        // Previously role-list-only cards follow the base role's old access.
        $this->assertContains('card.org.org_units.edit', $slugs($viewer));   // Branch Admin could edit units
        $this->assertContains('card.org.org_units.view', $slugs($editor));   // Manager could only list them
        $this->assertNotContains('card.org.org_units.edit', $slugs($editor));
        $this->assertNotContains('card.ops.mail_smtp.view', $slugs($viewer)); // was Company Admin only
        // A role never set up in the matrix is left alone.
        $this->assertSame([], $slugs($legacy));
    }

    public function test_device_control_needs_its_own_edit_tick(): void
    {
        $this->as('BRANCH_ADMIN', ['card.rules.app_web_rules.edit', 'card.rules.device_control.view']);
        $this->getJson('/api/enforcement/audit-report')->assertOk();
        $this->postJson('/api/enforcement/device-control', ['block_removable_storage' => true])->assertStatus(403);
        $this->as('BRANCH_ADMIN', ['card.rules.device_control.edit']);
        $this->postJson('/api/enforcement/device-control', ['block_removable_storage' => false])->assertStatus(200);
    }
}
