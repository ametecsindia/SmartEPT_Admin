<?php

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Models\BiometricEmployeeMapping;
use App\Models\Employee;
use App\Services\GateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 23-Sep-2026 (Ejaz): "every day I have to go to the Biometric tab and Sync, else the agent is
 * stuck at 'punch in at the door' although the employee already punched".
 *  1. The gate wall itself pulls the company's cloud reader — no scheduler, no Biometric tab.
 *  2. A device auto-disabled (ERROR) after failures is retried and re-armed by a good sync.
 */
class BiometricAutoSyncTest extends TestCase
{
    use RefreshDatabase;

    private Employee $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->update(['timezone' => config('app.timezone')]);
        $this->travelTo(Carbon::parse('2026-07-06 09:20:00'));
        $this->e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
    }

    private function cloudDevice(): BiometricDevice
    {
        $d = BiometricDevice::withoutGlobalScopes()->create([
            'company_id' => 1, 'name' => 'Door', 'integration_method' => 'DIRECT_PULL', 'status' => 'ACTIVE',
            'api_base_url' => 'https://api.etimeoffice.test', 'api_endpoint' => 'api/DownloadPunchData',
            'api_username' => 'u', 'api_password' => 'p', 'sync_mode' => 'INTERVAL', 'sync_interval_minutes' => 5,
        ]);
        BiometricEmployeeMapping::withoutGlobalScopes()->create([
            'company_id' => 1, 'employee_id' => $this->e->id, 'biometric_employee_id' => 'BIO-1001',
        ]);

        return $d;
    }

    public function test_the_gate_wall_pulls_door_punches_itself(): void
    {
        $this->cloudDevice();
        Http::fake(['*' => Http::response(['Error' => false, 'PunchData' => [
            ['Empcode' => 'BIO-1001', 'Name' => 'x', 'DateTime' => '06/07/2026 09:05:00', 'INOUT' => 'IN'],
        ]])]);

        $gate = app(GateService::class);
        $this->assertFalse($gate->statusFor($this->e->fresh())['open'], 'no punch pulled yet');

        $this->app->terminate(); // the pull runs after the gate answer is sent
        Http::assertSentCount(1);
        $this->assertTrue($gate->statusFor($this->e->fresh())['open'], 'punch pulled → gate opens, no Sync button');
    }

    public function test_the_live_sync_seconds_saved_in_the_console_drive_the_server_pull(): void
    {
        $this->cloudDevice();
        Http::fake(['*' => Http::response(['Error' => false, 'PunchData' => []])]);
        $admin = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $this->withToken($admin)->putJson('/api/integrations/biometric/live-sync', ['on' => true, 'seconds' => 1])
            ->assertOk()->assertJson(['on' => true, 'seconds' => 1]);
        $this->withToken($admin)->getJson('/api/integrations/biometric/live-sync')->assertJson(['on' => true, 'seconds' => 1]);

        $gate = app(GateService::class);
        $gate->statusFor($this->e->fresh());
        $this->app->terminate();
        $this->travel(2)->seconds();
        $gate->statusFor($this->e->fresh());
        $this->app->terminate();

        // The test app is not rebuilt between terminate() calls, so the first pull's callback
        // runs again: 1 + 1 (re-run) + 1 (NEW pull 2s later, because the cadence is 1s) = 3.
        Http::assertSentCount(3);
    }

    public function test_out_of_the_box_live_sync_is_on_every_second_with_nothing_configured(): void
    {
        $this->cloudDevice();
        Http::fake(['*' => Http::response(['Error' => false, 'PunchData' => []])]);
        $this->assertSame(['on' => true, 'seconds' => 1], GateService::liveSyncConfig(1));
        $gate = app(GateService::class);
        $gate->statusFor($this->e->fresh());
        $this->app->terminate();
        $this->travel(2)->seconds();
        $gate->statusFor($this->e->fresh());
        $this->app->terminate();

        Http::assertSentCount(3); // 1 + 1 re-run + 1 NEW pull — no Biometric tab, no Start button
    }

    public function test_after_an_admin_presses_stop_the_server_pull_waits_60_seconds(): void
    {
        $this->cloudDevice();
        Http::fake(['*' => Http::response(['Error' => false, 'PunchData' => []])]);
        \App\Models\Setting::put(GateService::liveSyncKey(1), json_encode(['on' => false, 'seconds' => 1]));
        $gate = app(GateService::class);
        $gate->statusFor($this->e->fresh());
        $this->app->terminate();
        $this->travel(2)->seconds();
        $gate->statusFor($this->e->fresh());
        $this->app->terminate();

        Http::assertSentCount(2); // 1 + 1 re-run — no new pull inside 60s
    }

    public function test_repeated_failures_slow_auto_sync_down_but_never_switch_it_off(): void
    {
        $d = $this->cloudDevice();
        $up = false;
        Http::fake(function () use (&$up) { return $up ? Http::response(['Error' => false, 'PunchData' => []]) : Http::response('down', 500); });
        foreach (range(1, 6) as $i) {
            $this->travel(6)->minutes();
            $this->artisan('smartept:biometric-sync');
        }
        $this->assertSame('ACTIVE', $d->fresh()->status, 'still eligible — never switched off');

        // Provider back: within 30 minutes it recovers by itself.
        $up = true;
        $this->travel(31)->minutes();
        $this->artisan('smartept:biometric-sync')->assertExitCode(0);
        $this->assertStringStartsWith('OK', $d->fresh()->last_sync_result);
    }
}
