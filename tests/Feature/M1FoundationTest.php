<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Milestone M1 smoke tests: auth, RBAC, tenant scoping, device registration,
 * and the composed policy bundle. Run on Laragon with: php artisan test
 */
class M1FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email, 'password' => 'password',
        ])->assertOk()->json('token');
    }

    public function test_company_admin_can_login_and_see_permissions(): void
    {
        $res = $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'password',
        ])->assertOk();

        $this->assertSame('COMPANY_ADMIN', $res->json('user.role'));
        $this->assertContains('policy.view', $res->json('user.permissions'));
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_employee_role_cannot_list_companies(): void
    {
        $token = $this->login('priya.raman@ametecs.io');
        $this->withToken($token)->getJson('/api/companies')->assertStatus(403);
    }

    public function test_admin_can_list_employees_scoped_to_tenant(): void
    {
        $token = $this->login('admin@ametecs.io');
        $res = $this->withToken($token)->getJson('/api/employees')->assertOk();
        $this->assertGreaterThanOrEqual(3, $res->json('total'));
    }

    public function test_agent_can_register_device_and_fetch_policy_bundle(): void
    {
        $token = $this->login('priya.raman@ametecs.io');

        $reg = $this->withToken($token)->postJson('/api/agent/register-device', [
            'device_uuid'   => 'TEST-DEVICE-001',
            'computer_name' => 'PRIYA-PC',
            'os_version'    => 'Windows 11',
        ])->assertCreated();

        $deviceToken = $reg->json('device_token');
        $this->assertNotEmpty($deviceToken);

        // The composed bundle should carry the company monitoring policy.
        $bundle = $this->withToken($deviceToken)
            ->getJson('/api/agent/policy?device_uuid=TEST-DEVICE-001')
            ->assertOk();

        $this->assertTrue($bundle->json('policies.monitoring.tracking_enabled'));
        $this->assertTrue($bundle->json('consent_required'));

        // Heartbeat with the device token.
        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'TEST-DEVICE-001', 'status' => 'ONLINE',
        ])->assertOk();
    }

    public function test_policy_edit_bumps_version(): void
    {
        $token = $this->login('compliance@ametecs.io');

        $list = $this->withToken($token)->getJson('/api/policies/monitoring')->assertOk();
        $id = $list->json('data.0.id');
        $before = $list->json('data.0.version');

        $res = $this->withToken($token)->putJson("/api/policies/monitoring/{$id}", [
            'description' => 'Updated in test',
        ])->assertOk();

        $this->assertSame($before + 1, $res->json('data.version'));
    }
}
