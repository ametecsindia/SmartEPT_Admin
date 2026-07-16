<?php

namespace Tests\Feature;

use App\Models\EmployeeAppUsageLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Milestone M4: app/website usage categorisation + blocked enforcement + compliance events.
 */
class M4UsageComplianceTest extends TestCase
{
    use RefreshDatabase;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $userToken = $this->postJson('/api/auth/login', [
            'email' => 'priya.raman@ametecs.io', 'password' => 'password',
        ])->json('token');

        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'M4-DEVICE',
        ])->json('device_token');

        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'M4-DEVICE', 'acknowledged' => true,
        ])->assertCreated();
    }

    public function test_app_usage_is_categorised_and_blocked_flagged(): void
    {
        $this->withToken($this->deviceToken)->postJson('/api/agent/app-usage', [
            'device_uuid' => 'M4-DEVICE',
            'events' => [
                ['app_name' => 'excel.exe', 'start_at' => now()->subMinutes(10)->toDateTimeString(), 'duration_seconds' => 600],
                ['app_name' => 'utorrent.exe', 'start_at' => now()->subMinutes(2)->toDateTimeString(), 'duration_seconds' => 120],
            ],
        ])->assertStatus(202)->assertJsonPath('stored', 2);

        $blocked = EmployeeAppUsageLog::where('app_name', 'utorrent.exe')->first();
        $this->assertSame('BLOCKED', $blocked->category);
        $this->assertSame('VIOLATION', $blocked->compliance_status);

        $prod = EmployeeAppUsageLog::where('app_name', 'excel.exe')->first();
        $this->assertSame('PRODUCTIVE', $prod->category);
    }

    public function test_blocked_website_flagged_via_title(): void
    {
        $this->withToken($this->deviceToken)->postJson('/api/agent/website-usage', [
            'device_uuid' => 'M4-DEVICE',
            'events' => [
                ['browser' => 'chrome.exe', 'page_title' => 'Facebook - Log In', 'start_at' => now()->toDateTimeString(), 'duration_seconds' => 90],
            ],
        ])->assertStatus(202);

        // Manager website report shows the blocked entry.
        $mgr = $this->postJson('/api/auth/login', ['email' => 'manager@ametecs.io', 'password' => 'password'])->json('token');
        $rep = $this->withToken($mgr)->getJson('/api/reports/employee/1/website-usage')->assertOk();
        $this->assertSame('BLOCKED', $rep->json('data.0.category'));
    }

    public function test_compliance_event_stored_and_in_feed(): void
    {
        $this->withToken($this->deviceToken)->postJson('/api/agent/compliance-event', [
            'device_uuid' => 'M4-DEVICE',
            'event_type' => 'BLOCKED_WEBSITE_OPENED',
            'event_category' => 'WEBSITE',
            'severity' => 'HIGH',
            'detected_value' => 'facebook.com',
            'action_taken' => 'WARNING_SHOWN',
            'screenshot_captured' => true,
        ])->assertCreated();

        $mgr = $this->postJson('/api/auth/login', ['email' => 'manager@ametecs.io', 'password' => 'password'])->json('token');
        $feed = $this->withToken($mgr)->getJson('/api/dashboard/violations')->assertOk();
        $this->assertGreaterThanOrEqual(1, $feed->json('total'));
    }
}
