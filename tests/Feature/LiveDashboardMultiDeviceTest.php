<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 26-Aug-2026 (Ejaz): "1 employee logging but not showing active on dashboard" — ACTIVE read 0
 * while the agent was demonstrably working, screenshots and violations landing all afternoon.
 *
 * The cause was not ingestion and not sign-out. `liveStatus()` did
 * `$devices->keyBy('employee_id')`, and keyBy keeps the LAST row for a duplicate key. An
 * employee with more than one device row — a replaced PC, a re-bind, a second desk — therefore
 * had an arbitrary one chosen, in table order. Enforce02 owned DESKTOP-KN8IQRK (heartbeat 24s
 * ago, session ACTIVE) and DESKTOP-LRCR8LO (dead since 24-Aug, FORCE_LOGOUT). The dead row won.
 */
class LiveDashboardMultiDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
        $this->travelTo(Carbon::parse('2026-07-07 16:22:28'));
    }

    private function live(): array
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'password',
        ])->assertOk()->json('token');

        return $this->withToken($token)->getJson('/api/dashboard/live-status')->assertOk()->json();
    }

    /**
     * The live device must win, whichever order the rows come back in. The dead row is created
     * SECOND on purpose — that is the order that produced the bug.
     */
    public function test_a_live_device_wins_over_the_employees_dead_one(): void
    {
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();

        EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-live-pc',
            'computer_name' => 'DESKTOP-KN8IQRK', 'session_status' => 'ACTIVE',
            'current_status' => 'ONLINE', 'last_heartbeat_at' => '2026-07-07 16:22:04',
            'compliance_status' => 'COMPLIANT',
        ]);
        EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-dead-pc',
            'computer_name' => 'DESKTOP-LRCR8LO', 'session_status' => 'FORCE_LOGOUT',
            'current_status' => 'OFFLINE', 'last_heartbeat_at' => '2026-07-05 19:47:44',
            'compliance_status' => 'CRITICAL',
        ]);

        $live = $this->live();

        $row = collect($live['employees'])->firstWhere('employee_id', $e->id);
        $this->assertNotNull($row);
        $this->assertSame('ONLINE', $row['status'], 'the live device must decide the employee status');
        // Identity, not a timestamp: the row must be built from the LIVE device row.
        $this->assertSame('COMPLIANT', $row['compliance_status'],
            'the row must be built from the live device, not the dead one');
        $this->assertSame(1, $live['cards']['active_now']);
    }

    /** With only a dead device the employee is correctly offline — the fix must not invert it. */
    public function test_only_a_dead_device_still_reads_offline(): void
    {
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();

        EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-dead-only',
            'session_status' => 'FORCE_LOGOUT', 'current_status' => 'OFFLINE',
            'last_heartbeat_at' => '2026-07-05 19:47:44',
        ]);

        $live = $this->live();
        $this->assertSame(0, $live['cards']['active_now']);
        $this->assertSame('OFFLINE', collect($live['employees'])->firstWhere('employee_id', $e->id)['status']);
    }
}
