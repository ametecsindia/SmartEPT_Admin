<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Milestone M2: consent gating + attendance/activity ingestion + today summary.
 */
class M2AgentTrackingTest extends TestCase
{
    use RefreshDatabase;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $userToken = $this->postJson('/api/auth/login', [
            'email' => 'priya.raman@ametecs.io', 'password' => 'password',
        ])->assertOk()->json('token');

        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'M2-DEVICE', 'computer_name' => 'PRIYA-PC',
        ])->assertCreated()->json('device_token');
    }

    public function test_tracking_is_blocked_until_consent_is_recorded(): void
    {
        $this->withToken($this->deviceToken)->postJson('/api/agent/activity-events', [
            'device_uuid' => 'M2-DEVICE',
            'events' => [['event_type' => 'ACTIVE', 'started_at' => now()->toDateTimeString(), 'duration_seconds' => 60]],
        ])->assertStatus(403)->assertJsonPath('error.code', 'CONSENT_REQUIRED');
    }

    public function test_full_attendance_and_activity_flow_after_consent(): void
    {
        // Freeze mid-day: events posted "minutes ago" must stay on today's date
        // regardless of when the suite runs (midnight runs used to flake).
        $this->travelTo(now()->startOfDay()->addHours(10));
        // 1) Record consent.
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'M2-DEVICE', 'acknowledged' => true,
        ])->assertCreated();

        // 2) Login attendance event.
        $this->withToken($this->deviceToken)->postJson('/api/agent/attendance-event', [
            'device_uuid' => 'M2-DEVICE', 'event_type' => 'LOGIN',
        ])->assertCreated();

        // 3) Activity batch (active + idle).
        $this->withToken($this->deviceToken)->postJson('/api/agent/activity-events', [
            'device_uuid' => 'M2-DEVICE',
            'events' => [
                ['event_type' => 'ACTIVE', 'started_at' => now()->subMinutes(5)->toDateTimeString(), 'duration_seconds' => 300, 'active_app' => 'chrome.exe', 'window_title' => 'SmartDCM'],
                ['event_type' => 'IDLE', 'started_at' => now()->subMinutes(2)->toDateTimeString(), 'duration_seconds' => 120],
            ],
        ])->assertStatus(202)->assertJsonPath('stored', 2);

        // 4) Break start/end.
        $this->withToken($this->deviceToken)->postJson('/api/agent/break-event', [
            'device_uuid' => 'M2-DEVICE', 'action' => 'START', 'break_type' => 'TEA',
        ])->assertCreated();

        // 5) Today summary reflects the active time.
        $today = $this->withToken($this->deviceToken)->getJson('/api/agent/today')->assertOk();
        $this->assertSame(300, $today->json('active_seconds'));
        $this->assertSame(120, $today->json('idle_seconds'));
        $this->assertNotNull($today->json('logged_in_at'));
    }
}
