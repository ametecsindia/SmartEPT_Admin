<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PcAuditController;
use App\Models\Company;
use App\Models\EmployeeDevice;
use App\Models\EnforcementMachine;
use App\Models\InstallationLicense;
use App\Models\PcAuditReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 04-Oct-2026 — PC Audit Log: Enforcer + Commander only; one timeline per PC; all-PC CSV in the background. */
class PcAuditTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['endpoint_security.enabled' => true]);
        $this->companyId = (int) Company::query()->orderBy('id')->value('id');

        $userToken = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', ['device_uuid' => 'PA-DEVICE'])->json('device_token');
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', ['device_uuid' => 'PA-DEVICE', 'acknowledged' => true])->assertCreated();
        EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'PA-DEVICE')->update(['computer_name' => 'DESKTOP-PA01']);
    }

    private function plan(string $tier): void
    {
        InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TEST-PCAU-0000-ABCD', 'status' => 'active', 'last_checked_at' => now(),
            'bundle' => ['kind' => 'subscription', 'tier' => $tier, 'status' => 'active', 'features' => ['enforcement' => $tier !== 'standard'],
                'expires_at' => now()->addYear()->toDateString(), 'grace_days' => 7, 'device_limit' => 100],
        ])->save();
    }

    private function admin(): void
    {
        $u = User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail();
        $this->withToken($this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password'])->assertOk()->json('token'));
    }

    private function machineToken(): string
    {
        $m = EnforcementMachine::withoutGlobalScopes()->create(['company_id' => $this->companyId, 'machine_id' => 'M-PA01',
            'hostname' => 'DESKTOP-PA01', 'device_uuid' => 'PA-DEVICE', 'enrolled_at' => now(), 'last_seen_at' => now()]);

        return $m->createToken('enforcer:PA01', ['enforcer'])->plainTextToken;
    }

    private function usb(string $policy): array
    {
        return ['kind' => 'usb_storage', 'time' => now()->utc()->toIso8601String(), 'title' => 'SanDisk Cruzer ' . $policy,
            'detail' => ['serial' => '4C53', 'capacity' => '16 GB', 'policy' => $policy, 'usbstor_disabled' => $policy === 'deny_all' ? '1' : '0']];
    }

    public function test_standard_gets_nothing(): void
    {
        $this->plan('standard');
        $this->withToken($this->machineToken())->postJson('/api/enforcer/pc-audit/sync', ['events' => [$this->usb('none')]])
            ->assertOk()->assertJsonPath('enabled', false);
        $this->withToken($this->deviceToken)->postJson('/api/agent/pc-audit/clicks', ['device_uuid' => 'PA-DEVICE',
            'clicks' => [['at' => now()->toIso8601String(), 'target' => 'Save']]])->assertStatus(403);
        $this->assertSame(0, DB::table('pc_audit_events')->count());
        $this->plan('enforcer');
        $this->withToken($this->deviceToken)->postJson('/api/agent/pc-audit/clicks', ['device_uuid' => 'SOMEONE-ELSES-PC',
            'clicks' => [['at' => now()->toIso8601String(), 'target' => 'Save']]])->assertStatus(403); // only its own PC
        $this->plan('standard');
        $this->admin();
        $this->getJson('/api/pc-audit/devices')->assertStatus(403)->assertJsonPath('error.code', 'FEATURE_NOT_AVAILABLE');
    }

    public function test_device_timeline_merges_every_source_with_honest_usb_outcomes(): void
    {
        $this->travelTo(now()->startOfSecond()); // the "resent batch" below must carry the SAME timestamp
        $this->plan('enforcer');
        $tok = $this->machineToken();
        $sync = fn (array $events) => $this->withToken($tok)->postJson('/api/enforcer/pc-audit/sync', ['events' => $events])->assertOk();

        $sync([$this->usb('none'), $this->usb('deny_all'),
            ['kind' => 'software_installed', 'time' => now()->utc()->toIso8601String(), 'title' => 'AnyDesk', 'detail' => ['version' => '8.0', 'publisher' => 'philandro']],
            ['kind' => 'download', 'time' => now()->utc()->toIso8601String(), 'title' => 'report.xlsx', 'detail' => ['from' => 'https://mail.example.com/x', 'size' => '20 KB']],
            ['kind' => 'evil_script', 'time' => now()->utc()->toIso8601String(), 'title' => 'x']]) // unknown kind: dropped
            ->assertJsonPath('stored', 4);
        $sync([$this->usb('none')]); // resent after a lost response — must not double

        Company::withoutGlobalScopes()->whereKey($this->companyId)->update(['block_removable_storage' => true]);
        $sync([array_replace($this->usb('deny_all'), ['title' => 'Kingston']),
            // 05-Oct-2026 live watcher: driver refused (error_code) = blocked attempt; other devices carry their own outcome
            ['kind' => 'usb_storage', 'time' => now()->utc()->toIso8601String(), 'title' => 'Cruzer Blade', 'detail' => ['policy' => 'none', 'usbstor_disabled' => '0', 'error_code' => '28']],
            ['kind' => 'device_connected', 'time' => now()->utc()->toIso8601String(), 'title' => 'Galaxy A52', 'detail' => ['class' => 'WPD', 'id' => 'SWD\\WPDBUSENUM\\x']],
            ['kind' => 'device_connected', 'time' => now()->utc()->toIso8601String(), 'title' => 'Unknown USB Device', 'detail' => ['error_code' => '43']]]);

        $this->withToken($this->deviceToken)->postJson('/api/agent/pc-audit/clicks', ['device_uuid' => 'PA-DEVICE', 'clicks' => [
            ['at' => now()->utc()->toIso8601String(), 'target' => 'Send', 'app' => 'chrome.exe', 'window' => 'Gmail', 'control' => 'button'],
        ]])->assertOk()->assertJsonPath('stored', 1);
        $this->withToken($this->deviceToken)->postJson('/api/agent/app-usage', ['device_uuid' => 'PA-DEVICE',
            'events' => [['app_name' => 'excel.exe', 'start_at' => now()->toDateTimeString(), 'duration_seconds' => 600]]])->assertStatus(202);

        $this->admin();
        $this->getJson('/api/pc-audit/devices')->assertOk()->assertJsonFragment(['device' => 'DESKTOP-PA01']);
        $d = $this->getJson('/api/pc-audit/devices/PA-DEVICE?from=' . now()->toDateString() . '&to=' . now()->toDateString())->assertOk();
        $out = collect($d->json('data'))->pluck('outcome', 'event');
        $this->assertSame('Allowed', $out['USB storage connected: SanDisk Cruzer none']);
        $this->assertSame('Blocked by another policy on this PC (not SmartEPT)', $out['⚠ Blocked — employee tried to connect: SanDisk Cruzer deny_all']);
        $this->assertSame('Blocked by SmartEPT', $out['⚠ Blocked — employee tried to connect: Kingston']);
        $this->assertSame('Blocked by SmartEPT', $out['⚠ Blocked — employee tried to connect: Cruzer Blade']);
        $this->assertSame('Allowed', $out['Device connected: Galaxy A52']);
        $this->assertSame('Blocked — Windows refused the device (code 43)', $out['⚠ Blocked — employee tried to connect: Unknown USB Device']);
        $this->assertArrayHasKey('Installed AnyDesk', $out);
        $this->assertArrayHasKey('Downloaded report.xlsx', $out);
        $this->assertArrayHasKey('Clicked "Send"', $out);
        $this->assertArrayHasKey('Used excel.exe', $out);
        $this->assertSame(6, $d->json('counts.device'));

        $only = $this->getJson('/api/pc-audit/devices/PA-DEVICE?category=click')->assertOk();
        $this->assertSame(['click'], array_keys($only->json('counts')));

        $csv = $this->get('/api/pc-audit/devices/PA-DEVICE/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Blocked by SmartEPT', $csv);

        $this->getJson('/api/pc-audit/devices/NOT-MINE')->assertStatus(404);
        $this->getJson('/api/pc-audit/devices/PA-DEVICE?from=2026-01-01&to=2026-09-01')->assertStatus(422);
    }

    public function test_all_pc_report_runs_in_background_and_downloads(): void
    {
        $this->plan('commander');
        $this->withToken($this->machineToken())->postJson('/api/enforcer/pc-audit/sync', ['events' => [$this->usb('none')]])->assertOk();
        $this->admin();
        $id = $this->postJson('/api/pc-audit/reports', ['from' => now()->toDateString(), 'to' => now()->toDateString()])
            ->assertStatus(202)->json('data.id');

        // afterResponse() already ran it inside the test request; run() is idempotent either way.
        PcAuditController::runReport($id);
        $r = PcAuditReport::withoutGlobalScopes()->find($id);
        $this->assertSame('done', $r->status, (string) $r->error);
        $this->assertGreaterThanOrEqual(1, $r->rows);
        $this->getJson('/api/pc-audit/reports')->assertOk()->assertJsonPath('data.0.status', 'done');
        $this->assertStringContainsString('DESKTOP-PA01', file_get_contents($this->get("/api/pc-audit/reports/$id/download")->assertOk()->baseResponse->getFile()->getPathname()));
    }
}
