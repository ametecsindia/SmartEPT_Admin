<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MailLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Release-1 item 1: user account lifecycle — admin-provisioned logins with
 * one-time temp passwords, forced password change, token revocation on
 * reset/disable, employee auto-provisioning, and tenant isolation.
 */
class M7UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(string $email, string $password = 'password'): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email, 'password' => $password,
        ])->assertOk()->json('token');
    }

    /** Create a user via the API as the given admin; returns [id, email, temp_password]. */
    private function createUser(string $adminToken, string $email, string $role = 'MANAGER'): array
    {
        $res = $this->withToken($adminToken)->postJson('/api/users', [
            'name' => 'Test Person', 'email' => $email, 'role' => $role,
        ])->assertCreated();

        $temp = $res->json('temp_password');
        $this->assertNotEmpty($temp);
        $this->assertSame(10, strlen($temp));

        return [$res->json('data.id'), $email, $temp];
    }

    public function test_admin_creates_user_and_temp_password_login_works(): void
    {
        $admin = $this->login('admin@ametecs.io');
        [$id, $email, $temp] = $this->createUser($admin, 'new.manager@ametecs.io');

        // The temp password works, and the login response flags the forced change.
        $res = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $temp])->assertOk();
        $this->assertTrue($res->json('user.must_change_password'));
        $this->assertSame('MANAGER', $res->json('user.role'));

        // Credentials mail attempt is always recorded in mail_logs.
        $log = MailLog::where('to', $email)->where('kind', 'USER_CREDENTIALS')->first();
        $this->assertNotNull($log);
        $this->assertSame('Your SmartEPT sign-in', $log->subject);

        // New user shows up in the tenant listing with role info.
        $list = $this->withToken($admin)->getJson('/api/users?q=new.manager')->assertOk();
        $this->assertSame($id, $list->json('data.0.id'));
        $this->assertSame('MANAGER', $list->json('data.0.role'));
    }

    public function test_non_super_admin_cannot_create_super_admin(): void
    {
        $admin = $this->login('admin@ametecs.io');
        $this->withToken($admin)->postJson('/api/users', [
            'name' => 'Evil Twin', 'email' => 'evil@ametecs.io', 'role' => 'SUPER_ADMIN',
        ])->assertStatus(422);
    }

    public function test_change_password_flow(): void
    {
        $admin = $this->login('admin@ametecs.io');
        [, $email, $temp] = $this->createUser($admin, 'changer@ametecs.io');

        $token = $this->login($email, $temp);
        $this->withToken($token)->postJson('/api/auth/change-password', [
            'current_password'          => $temp,
            'new_password'              => 'BrandNew#123',
            'new_password_confirmation' => 'BrandNew#123',
        ])->assertOk();

        // Old (temp) password stops working, the new one works, flag is cleared.
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $temp])->assertStatus(422);
        $res = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'BrandNew#123'])->assertOk();
        $this->assertFalse($res->json('user.must_change_password'));

        // The token used to change the password stays valid (only others are revoked).
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $token = $this->login('manager@ametecs.io');
        $this->withToken($token)->postJson('/api/auth/change-password', [
            'current_password'          => 'not-the-password',
            'new_password'              => 'BrandNew#123',
            'new_password_confirmation' => 'BrandNew#123',
        ])->assertStatus(422);
    }

    public function test_reset_password_revokes_existing_tokens(): void
    {
        $admin = $this->login('admin@ametecs.io');
        [$id, $email, $temp] = $this->createUser($admin, 'resettee@ametecs.io');

        $oldDeviceToken = $this->login($email, $temp);

        $res = $this->withToken($admin)->postJson("/api/users/{$id}/reset-password")->assertOk();
        $newTemp = $res->json('temp_password');
        $this->assertNotEmpty($newTemp);
        $this->assertNotSame($temp, $newTemp);

        // The pre-reset session is dead; the new temp password signs in fresh.
        $this->withToken($oldDeviceToken)->getJson('/api/auth/me')->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $temp])->assertStatus(422);
        $this->login($email, $newTemp);
    }

    public function test_disabled_user_cannot_login_and_tokens_die(): void
    {
        $admin = $this->login('admin@ametecs.io');
        [$id, $email, $temp] = $this->createUser($admin, 'doomed@ametecs.io');
        $token = $this->login($email, $temp);

        $this->withToken($admin)->deleteJson("/api/users/{$id}")->assertNoContent();

        // Existing session revoked + fresh login blocked with the 403 disabled error.
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $temp])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    }

    public function test_admin_cannot_disable_self_or_change_own_role(): void
    {
        $admin = $this->login('admin@ametecs.io');
        $selfId = $this->withToken($admin)->getJson('/api/auth/me')->json('user.id');

        $this->withToken($admin)->deleteJson("/api/users/{$selfId}")->assertStatus(422);
        $this->withToken($admin)->putJson("/api/users/{$selfId}", ['status' => 'DISABLED'])->assertStatus(422);
        $this->withToken($admin)->putJson("/api/users/{$selfId}", ['role' => 'EMPLOYEE'])->assertStatus(422);
    }

    public function test_employee_role_cannot_access_user_management(): void
    {
        $token = $this->login('priya.raman@ametecs.io');
        $this->withToken($token)->getJson('/api/users')->assertStatus(403);
        $this->withToken($token)->postJson('/api/users', [
            'name' => 'X', 'email' => 'x@ametecs.io', 'role' => 'EMPLOYEE',
        ])->assertStatus(403);
    }

    public function test_creating_employee_auto_creates_linked_login(): void
    {
        $hr = $this->login('hr@ametecs.io');

        $res = $this->withToken($hr)->postJson('/api/employees', [
            'employee_code' => 'E-2001',
            'first_name'    => 'Nisha',
            'last_name'     => 'Kumar',
            'email'         => 'nisha.kumar@ametecs.io',
        ])->assertCreated();

        $temp = $res->json('temp_password');
        $this->assertNotEmpty($temp);

        // The auto-provisioned login works and carries the EMPLOYEE role + link.
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'nisha.kumar@ametecs.io', 'password' => $temp,
        ])->assertOk();
        $this->assertSame('EMPLOYEE', $loginRes->json('user.role'));
        $this->assertTrue($loginRes->json('user.must_change_password'));

        $user = User::where('email', 'nisha.kumar@ametecs.io')->first();
        $this->assertSame($user->id, $user->employee->user_id);
        $this->assertSame('E-2001', $user->employee->employee_code);
    }

    public function test_create_login_false_skips_auto_provisioning(): void
    {
        $hr = $this->login('hr@ametecs.io');

        $res = $this->withToken($hr)->postJson('/api/employees', [
            'employee_code' => 'E-2002',
            'first_name'    => 'NoLogin',
            'email'         => 'no.login@ametecs.io',
            'create_login'  => false,
        ])->assertCreated();

        $this->assertNull($res->json('temp_password'));
        $this->assertNull(User::where('email', 'no.login@ametecs.io')->first());
    }

    public function test_duplicate_employee_code_returns_422_not_500(): void
    {
        $hr = $this->login('hr@ametecs.io');

        // E-1001 is seeded — a duplicate must be a validation error, not a DB explosion.
        $this->withToken($hr)->postJson('/api/employees', [
            'employee_code' => 'E-1001',
            'first_name'    => 'Duplicate',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_code');
    }

    public function test_cross_tenant_admin_cannot_see_or_touch_other_company_users(): void
    {
        // Second tenant built directly via models (no provisioning API round-trip).
        $company2 = Company::create([
            'name' => 'Other Corp', 'code' => 'OTHER', 'timezone' => 'Asia/Kolkata', 'status' => 'ACTIVE',
        ]);
        $adminRole = Role::whereNull('company_id')->where('slug', 'COMPANY_ADMIN')->first();
        User::create([
            'name' => 'Other Admin', 'email' => 'admin@other.io',
            'password' => Hash::make('password'), 'company_id' => $company2->id,
            'role_id' => $adminRole->id, 'status' => 'ACTIVE',
        ]);

        $otherAdmin = $this->login('admin@other.io');

        // Listing only shows company-2 users — none of the Ametecs accounts leak.
        $list = $this->withToken($otherAdmin)->getJson('/api/users')->assertOk();
        $emails = array_column($list->json('data'), 'email');
        $this->assertContains('admin@other.io', $emails);
        $this->assertNotContains('admin@ametecs.io', $emails);
        $this->assertNotContains('priya.raman@ametecs.io', $emails);

        // Direct access to a company-1 user 404s (existence not confirmed).
        $target = User::where('email', 'manager@ametecs.io')->first();
        $this->withToken($otherAdmin)->putJson("/api/users/{$target->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->withToken($otherAdmin)->postJson("/api/users/{$target->id}/reset-password")->assertNotFound();
        $this->withToken($otherAdmin)->deleteJson("/api/users/{$target->id}")->assertNotFound();
        $this->assertSame('Team Manager', $target->fresh()->name);

        // Super Admin crosses tenants: sees both companies' users.
        $super = $this->login('super@smartept.io');
        $all = $this->withToken($super)->getJson('/api/users?per_page=100')->assertOk();
        $allEmails = array_column($all->json('data'), 'email');
        $this->assertContains('admin@other.io', $allEmails);
        $this->assertContains('admin@ametecs.io', $allEmails);
    }
}
