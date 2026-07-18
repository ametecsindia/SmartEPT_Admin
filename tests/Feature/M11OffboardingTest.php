<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * R2-3 offboarding + device management: relieve revokes everything at once,
 * relieved agents are blocked as a backstop, unbind/rebind controls the
 * device lifecycle (and its licence seat).
 */
class M11OffboardingTest extends TestCase
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

    private function registerAgent(string $uuid = 'OFF-DEV-1'): array
    {
        $userToken = $this->login('priya.raman@ametecs.io');
        $res = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => $uuid, 'computer_name' => 'OFF-PC',
        ])->assertCreated();

        return [$userToken, $res->json('device_token')];
    }

    public function test_relieve_disables_login_revokes_tokens_and_unbinds_devices(): void
    {
        [, $deviceToken] = $this->registerAgent();
        $employee = Employee::where('user_id', '!=', null)
            ->whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();

        $admin = $this->login('admin@ametecs.io');
        $this->withToken($admin)->postJson("/api/employees/{$employee->id}/relieve", [
            'reason' => 'Resigned — last working day completed.',
        ])->assertOk()->assertJsonPath('data.employment_status', 'RELIEVED');

        // Device unbound + offline.
        $device = EmployeeDevice::where('device_uuid', 'OFF-DEV-1')->first();
        $this->assertNotNull($device->unbound_at);
        $this->assertSame('OFFLINE', $device->current_status);

        // Agent token revoked → 401.
        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'OFF-DEV-1',
        ])->assertStatus(401);

        // Login disabled.
        $this->postJson('/api/auth/login', [
            'email' => 'priya.raman@ametecs.io', 'password' => 'password',
        ])->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    }

    public function test_relieve_requires_reason(): void
    {
        $employee = Employee::first();
        $admin = $this->login('admin@ametecs.io');

        $this->withToken($admin)->postJson("/api/employees/{$employee->id}/relieve", [])
            ->assertStatus(422);
    }

    public function test_relieved_employee_agent_is_blocked_even_with_live_token(): void
    {
        [, $deviceToken] = $this->registerAgent('OFF-DEV-2');

        // Flip status directly WITHOUT revoking tokens — the middleware backstop.
        Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))
            ->update(['employment_status' => 'RELIEVED']);

        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'OFF-DEV-2',
        ])->assertStatus(403)->assertJsonPath('error.code', 'EMPLOYMENT_INACTIVE');
    }

    public function test_unbound_device_cannot_reregister_until_rebind_approved(): void
    {
        [$userToken] = $this->registerAgent('OFF-DEV-3');
        $device = EmployeeDevice::where('device_uuid', 'OFF-DEV-3')->first();

        $admin = $this->login('admin@ametecs.io');
        $this->withToken($admin)->postJson("/api/devices/{$device->id}/unbind")
            ->assertOk();

        $this->assertNotNull($device->fresh()->unbound_at);

        // Silent re-registration refused.
        $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'OFF-DEV-3',
        ])->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_UNBOUND');

        // Admin approves re-bind (no licence configured → no Central call needed).
        $this->withToken($admin)->postJson("/api/devices/{$device->id}/rebind")->assertOk();
        $this->assertNull($device->fresh()->unbound_at);

        $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'OFF-DEV-3',
        ])->assertCreated();
    }

    public function test_deleted_employee_code_can_be_reused(): void
    {
        $admin = $this->login('admin@ametecs.io');

        $created = $this->withToken($admin)->postJson('/api/employees', [
            'employee_code' => 'E-REUSE-1', 'first_name' => 'Gulab', 'last_name' => 'Test',
        ])->assertCreated();

        $this->withToken($admin)->deleteJson('/api/employees/' . $created->json('data.id'))
            ->assertStatus(204);

        // Same employee ID for the replacement hire — must work (soft-deleted row freed it).
        $this->withToken($admin)->postJson('/api/employees', [
            'employee_code' => 'E-REUSE-1', 'first_name' => 'Rahim', 'last_name' => 'Replacement',
        ])->assertCreated();
    }

    public function test_rebind_respects_licence_seat_limit(): void
    {
        $this->registerAgent('OFF-DEV-4');
        $device = EmployeeDevice::where('device_uuid', 'OFF-DEV-4')->first();

        config(['smartept.license_url' => 'https://central.fake']);
        \App\Models\InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TEST-TEST-TEST-ABCD',
            'status' => 'active',
            'bundle' => ['device_limit' => 1, 'grace_days' => 7],
            'last_checked_at' => now(),
        ])->save();

        $admin = $this->login('admin@ametecs.io');
        $this->withToken($admin)->postJson("/api/devices/{$device->id}/unbind")->assertOk();

        Http::fake([
            'central.fake/api/v1/license/device/activate' => Http::response([
                'ok' => false, 'reason' => 'device_limit_reached',
            ], 409),
        ]);

        $this->withToken($admin)->postJson("/api/devices/{$device->id}/rebind")
            ->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_SEAT_BLOCKED');
    }
}
