<?php

namespace Tests\Feature;

use App\Models\InstallationLicense;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * R2-1 licence wiring: key set/validate, agent gating with grace window,
 * seat enforcement on device registration, offline tolerance.
 * Central is always faked — no real phone-home from tests.
 */
class M9LicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['smartept.license_url' => 'https://central.fake']);
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email, 'password' => 'password',
        ])->assertOk()->json('token');
    }

    private function registerDevice(string $uuid = 'LIC-TEST-DEVICE-1'): string
    {
        $token = $this->login('priya.raman@ametecs.io');

        return $this->withToken($token)->postJson('/api/agent/register-device', [
            'device_uuid' => $uuid, 'computer_name' => 'LIC-PC',
        ])->assertCreated()->json('device_token');
    }

    private function activeBundle(array $overrides = []): array
    {
        return array_merge([
            'key' => 'SEPT-TEST-TEST-TEST-ABCD',
            'company' => 'ABC Recoveries',
            'plan' => 'PRO',
            'kind' => 'subscription',
            'deployment' => 'client_hosted',
            'device_limit' => 25,
            'features' => ['screenshots'],
            'status' => 'active',
            'expires_at' => now()->addYear()->toDateString(),
            'grace_days' => 7,
            'signature' => 'sig',
        ], $overrides);
    }

    public function test_license_endpoints_require_admin_role(): void
    {
        $token = $this->login('priya.raman@ametecs.io');
        $this->withToken($token)->getJson('/api/license')->assertStatus(403);
    }

    public function test_admin_sets_key_and_bundle_is_cached(): void
    {
        Http::fake(['central.fake/*' => Http::response(['ok' => true, 'bundle' => $this->activeBundle()])]);

        $token = $this->login('admin@ametecs.io');
        $res = $this->withToken($token)->postJson('/api/license', ['key' => 'SEPT-TEST-TEST-TEST-ABCD'])->assertOk();

        $this->assertSame('active', $res->json('status'));
        $this->assertTrue($res->json('operational'));
        $this->assertSame(25, $res->json('device_limit'));
        $this->assertSame('PRO', $res->json('plan'));
        $this->assertStringContainsString('••••', $res->json('key_masked'));

        $lic = InstallationLicense::current();
        $this->assertSame('active', $lic->status);
        $this->assertNotNull($lic->last_checked_at);
    }

    public function test_bad_key_blocks_agent_sync(): void
    {
        // Register while still unlicensed (allowed), then a bad key gets configured.
        $deviceToken = $this->registerDevice();

        Http::fake(['central.fake/*' => Http::response(['ok' => false, 'reason' => 'unknown_key'], 403)]);
        $admin = $this->login('admin@ametecs.io');
        $this->withToken($admin)->postJson('/api/license', ['key' => 'SEPT-BAD0-BAD0-BAD0-XXXX'])->assertOk();

        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'LIC-TEST-DEVICE-1', 'status' => 'ONLINE',
        ])->assertStatus(403)->assertJsonPath('error.code', 'LICENSE_BLOCKED');
    }

    public function test_expired_license_honours_grace_window(): void
    {
        $deviceToken = $this->registerDevice();

        // Expired 3 days ago with 7 grace days → still operational.
        InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TEST-TEST-TEST-ABCD',
            'status' => 'expired',
            'bundle' => $this->activeBundle(['expires_at' => now()->subDays(3)->toDateString()]),
            'last_checked_at' => now(),
        ])->save();

        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'LIC-TEST-DEVICE-1',
        ])->assertOk();

        // Expired 11 days ago → grace exhausted, sync blocked.
        InstallationLicense::current()->forceFill([
            'bundle' => $this->activeBundle(['expires_at' => now()->subDays(11)->toDateString()]),
        ])->save();

        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'LIC-TEST-DEVICE-1',
        ])->assertStatus(403)->assertJsonPath('error.reason', 'expired');
    }

    public function test_seat_limit_blocks_new_device_but_not_reregistration(): void
    {
        // Existing device registered before the licence was configured.
        $this->registerDevice('LIC-SEAT-DEV-1');

        InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TEST-TEST-TEST-ABCD',
            'status' => 'active',
            'bundle' => $this->activeBundle(['device_limit' => 1]),
            'last_checked_at' => now(),
        ])->save();

        Http::fake([
            'central.fake/api/v1/license/device/activate' => Http::response([
                'ok' => false, 'reason' => 'device_limit_reached', 'device_limit' => 1,
            ], 409),
        ]);

        // The single-session guard (newer feature) would otherwise answer first:
        // an ACTIVE session on DEV-1 blocks a second device with SINGLE_SESSION_ACTIVE
        // before the seat check runs. Log DEV-1's session out so the SEAT path is
        // what this test actually exercises.
        \App\Models\EmployeeDevice::withoutGlobalScopes()
            ->where('device_uuid', 'LIC-SEAT-DEV-1')
            ->update(['session_status' => 'LOGGED_OUT', 'last_heartbeat_at' => now()->subHours(2)]);

        // Brand-new device → Central refuses the seat → 409 to the agent.
        $token = $this->login('priya.raman@ametecs.io');
        $this->withToken($token)->postJson('/api/agent/register-device', [
            'device_uuid' => 'LIC-SEAT-DEV-2',
        ])->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_SEAT_BLOCKED');

        // Re-registering the KNOWN device claims no new seat → still fine.
        $this->withToken($token)->postJson('/api/agent/register-device', [
            'device_uuid' => 'LIC-SEAT-DEV-1',
        ])->assertCreated();
    }

    public function test_central_unreachable_keeps_last_verdict(): void
    {
        $deviceToken = $this->registerDevice();

        InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TEST-TEST-TEST-ABCD',
            'status' => 'active',
            'bundle' => $this->activeBundle(),
            'last_checked_at' => now(),
        ])->save();

        Http::fake(['central.fake/*' => fn () => throw new ConnectionException('Central is down')]);

        // Offline-first (EPT-29, 5-Aug): "Validate now" does NOT phone home while the
        // cached verdict is active — so the unreachable path is exercised the way it
        // really happens: the DAILY revalidate. Make the verdict stale; the next agent
        // heartbeat triggers the refresh, Central is down, the cached verdict stands.
        InstallationLicense::current()->forceFill(['last_checked_at' => now()->subDays(2)])->save();

        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'LIC-TEST-DEVICE-1',
        ])->assertOk();

        $admin = $this->login('admin@ametecs.io');
        $res = $this->withToken($admin)->getJson('/api/license')->assertOk();
        $this->assertSame('active', $res->json('status'));
        $this->assertTrue($res->json('operational'));
        $this->assertStringContainsString('unreachable', $res->json('last_error'));
    }

    public function test_unlicensed_install_runs_during_seven_day_evaluation(): void
    {
        $admin = $this->login('admin@ametecs.io');
        $res = $this->withToken($admin)->getJson('/api/license')->assertOk();

        $this->assertFalse($res->json('configured'));
        $this->assertTrue($res->json('operational'));
        $this->assertSame(7, $res->json('evaluation_days_left'));
        $this->registerDevice('LIC-FREE-DEV'); // asserts 201 inside
    }

    public function test_unlicensed_install_blocks_everything_after_seven_days(): void
    {
        // Ejaz's rule: 7-day evaluation, then block until a key is entered.
        $deviceToken = $this->registerDevice('LIC-EVAL-DEV'); // creates the licence row (day 0)

        $this->travelTo(now()->addDays(8));

        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'LIC-EVAL-DEV',
        ])->assertStatus(403)->assertJsonPath('error.reason', 'evaluation_expired');

        // 14-Aug-2026: an ordinary employee can no longer even sign in once the
        // licence is dead — the console stops, not just agent ingestion.
        $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])
            ->assertStatus(403)->assertJsonPath('error.code', 'LICENSE_BLOCKED');

        // New device registration is blocked too — the Company Admin may sign in
        // (they are the rescue route) but agent endpoints are still closed to them.
        $userToken = $this->login('admin@ametecs.io');
        $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'LIC-EVAL-DEV-2',
        ])->assertStatus(403)->assertJsonPath('error.code', 'LICENSE_BLOCKED');

        // Console (Licence screen) still reachable so the key can be entered…
        $admin = $this->login('admin@ametecs.io');
        $this->assertSame(0, $this->withToken($admin)->getJson('/api/license')->assertOk()->json('evaluation_days_left'));

        // …and entering a valid key unblocks instantly.
        Http::fake(['central.fake/*' => Http::response(['ok' => true, 'bundle' => $this->activeBundle()])]);
        $this->withToken($admin)->postJson('/api/license', ['key' => 'SEPT-TEST-TEST-TEST-ABCD'])->assertOk();

        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'LIC-EVAL-DEV',
        ])->assertOk();
    }

    public function test_expired_trial_blocks_immediately_no_grace(): void
    {
        $deviceToken = $this->registerDevice('LIC-TRIAL-DEV');

        // A trial bundle that expired YESTERDAY with 0 grace days → blocked now.
        InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TRIA-LKEY-TEST-ABCD',
            'status' => 'expired',
            'bundle' => $this->activeBundle([
                'kind' => 'trial',
                'expires_at' => now()->subDay()->toDateString(),
                'grace_days' => 0,
            ]),
            'last_checked_at' => now(),
        ])->save();

        $this->withToken($deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'LIC-TRIAL-DEV',
        ])->assertStatus(403)->assertJsonPath('error.reason', 'expired');
    }
}
