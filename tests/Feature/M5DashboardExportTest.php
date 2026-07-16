<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Milestone M5: live dashboard, employee timeline, CSV exports, sync-batch idempotency.
 */
class M5DashboardExportTest extends TestCase
{
    use RefreshDatabase;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $userToken = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', ['device_uuid' => 'M5-DEVICE'])->json('device_token');
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', ['device_uuid' => 'M5-DEVICE', 'acknowledged' => true]);

        $this->withToken($this->deviceToken)->postJson('/api/agent/attendance-event', ['device_uuid' => 'M5-DEVICE', 'event_type' => 'LOGIN']);
        $this->withToken($this->deviceToken)->postJson('/api/agent/activity-events', [
            'device_uuid' => 'M5-DEVICE',
            'events' => [['event_type' => 'ACTIVE', 'started_at' => now()->subMinutes(5)->toDateTimeString(), 'duration_seconds' => 300]],
        ]);
    }

    private function mgr(): string
    {
        return $this->postJson('/api/auth/login', ['email' => 'manager@ametecs.io', 'password' => 'password'])->json('token');
    }

    public function test_live_status_cards_and_table(): void
    {
        $res = $this->withToken($this->mgr())->getJson('/api/dashboard/live-status')->assertOk();
        $this->assertArrayHasKey('total_employees', $res->json('cards'));
        $this->assertGreaterThanOrEqual(3, $res->json('cards.total_employees'));
        $this->assertNotEmpty($res->json('employees'));
    }

    public function test_employee_timeline_has_login_entry(): void
    {
        $res = $this->withToken($this->mgr())->getJson('/api/reports/employee/1/timeline')->assertOk();
        $types = collect($res->json('timeline'))->pluck('type');
        $this->assertTrue($types->contains('LOGIN'));
    }

    public function test_productivity_csv_export(): void
    {
        $res = $this->withToken($this->mgr())->get('/api/export/productivity')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
    }

    public function test_sync_batch_is_idempotent(): void
    {
        $first = $this->withToken($this->deviceToken)->postJson('/api/agent/sync-batch', [
            'device_uuid' => 'M5-DEVICE', 'batch_uuid' => 'BATCH-XYZ', 'event_count' => 5,
        ])->assertOk();
        $this->assertFalse($first->json('already_processed'));

        $second = $this->withToken($this->deviceToken)->postJson('/api/agent/sync-batch', [
            'device_uuid' => 'M5-DEVICE', 'batch_uuid' => 'BATCH-XYZ', 'event_count' => 5,
        ])->assertOk();
        $this->assertTrue($second->json('already_processed'));
    }
}
