<?php

namespace Tests\Feature;

use App\Models\ScreenshotAccessLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Milestone M3: policy-gated screenshot upload + secure serving + access audit,
 * and metadata-only presence events.
 */
class M3ScreenshotPresenceTest extends TestCase
{
    use RefreshDatabase;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $userToken = $this->postJson('/api/auth/login', [
            'email' => 'priya.raman@ametecs.io', 'password' => 'password',
        ])->json('token');

        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'M3-DEVICE',
        ])->json('device_token');

        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'M3-DEVICE', 'acknowledged' => true,
        ])->assertCreated();
    }

    public function test_presence_event_metadata_is_accepted(): void
    {
        $this->withToken($this->deviceToken)->postJson('/api/agent/presence-event', [
            'device_uuid' => 'M3-DEVICE',
            'event_type'  => 'PRESENT',
            'confidence_score' => 0.88,
            'started_at'  => now()->toDateTimeString(),
            'metadata'    => ['face_count' => 1, 'brightness' => 140],
        ])->assertCreated();
    }

    public function test_screenshot_upload_store_serve_and_audit(): void
    {
        // Upload (seeded screenshot policy is enabled).
        $up = $this->withToken($this->deviceToken)->post('/api/agent/screenshot-upload', [
            'device_uuid'   => 'M3-DEVICE',
            'active_app'    => 'chrome.exe',
            'window_title'  => 'SmartDCM',
            'trigger_reason' => 'INTERVAL',
            'image'         => UploadedFile::fake()->image('shot.jpg', 800, 600),
        ])->assertCreated();

        $shotId = $up->json('screenshot_id');
        $this->assertNotNull($shotId);

        // Manager views the timeline.
        $mgr = $this->postJson('/api/auth/login', ['email' => 'manager@ametecs.io', 'password' => 'password'])->json('token');
        $timeline = $this->withToken($mgr)->getJson('/api/reports/employee/1/screenshots')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($timeline->json('data')));

        // Manager opens the protected file → recorded in the access log.
        $this->withToken($mgr)->get("/api/screenshots/{$shotId}/file")->assertOk();
        $this->assertSame(1, ScreenshotAccessLog::where('employee_screenshot_log_id', $shotId)->count());
    }

    public function test_employee_role_cannot_view_screenshots(): void
    {
        $emp = $this->postJson('/api/auth/login', ['email' => 'dev.patel@ametecs.io', 'password' => 'password'])->json('token');
        $this->withToken($emp)->getJson('/api/reports/employee/1/screenshots')->assertStatus(403);
    }
}
