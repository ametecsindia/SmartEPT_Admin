<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\StatusTimeline;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 28-Sep-2026 (Ejaz): agent window says Active, Live Dashboard says Idle. The board read
 * Active/Idle from the status timeline, which trails the agent by a flush + sync cycle.
 * It now reads it from the heartbeat; breaks/meetings still come from the timeline.
 */
class LiveDashboardActiveIdleTest extends TestCase
{
    use RefreshDatabase;

    private Employee $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
        $this->travelTo(Carbon::parse('2026-07-07 16:22:28'));
        $this->e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
    }

    private function row(string $deviceStatus, string $segState): array
    {
        EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $this->e->id, 'device_uuid' => 'uuid-ai',
            'computer_name' => 'PC-AI', 'session_status' => 'ACTIVE',
            'current_status' => $deviceStatus, 'last_heartbeat_at' => '2026-07-07 16:22:10',
        ]);
        StatusTimeline::withoutGlobalScopes()->create([
            'company_id' => 1, 'employee_id' => $this->e->id, 'device_uuid' => 'uuid-ai',
            'state' => $segState, 'started_at' => '2026-07-07 16:10:00', 'source' => 'AGENT',
        ]);
        $token = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $live = $this->withToken($token)->getJson('/api/dashboard/live-status')->assertOk()->json();

        return collect($live['employees'])->firstWhere('employee_id', $this->e->id);
    }

    public function test_heartbeat_active_beats_a_stale_idle_timeline(): void
    {
        $this->assertSame('ACTIVE', $this->row('ONLINE', 'IDLE')['work_status']);
    }

    public function test_heartbeat_idle_beats_a_stale_active_timeline(): void
    {
        $this->assertSame('IDLE', $this->row('IDLE', 'ACTIVE')['work_status']);
    }

    public function test_a_break_still_comes_from_the_timeline(): void
    {
        $this->assertSame('LUNCH_BREAK', $this->row('ONLINE', 'LUNCH_BREAK')['work_status']);
    }
}
