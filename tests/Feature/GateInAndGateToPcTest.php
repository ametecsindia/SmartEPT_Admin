<?php

namespace Tests\Feature;

use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 23-Sep-2026 (Ejaz): the agent shows Gate IN and Sign IN side by side (never one replacing the
 * other), and the productivity report measures the day from the door, with Gate→PC as its own
 * column — so not a second between the door and the desk is lost.
 */
class GateInAndGateToPcTest extends TestCase
{
    use RefreshDatabase;

    private Employee $e;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->update(['timezone' => config('app.timezone')]);
        $this->travelTo(Carbon::parse('2026-07-06 12:00:00'));
        $this->e = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->firstOrFail();
        $this->admin = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');

        BiometricLog::withoutGlobalScopes()->create([
            'company_id' => $this->e->company_id, 'employee_id' => $this->e->id, 'biometric_employee_id' => 'B1',
            'punch_type' => 'IN', 'punched_at' => '2026-07-06 09:05:00', 'source' => 'CLOUD_API',
        ]);
        EmployeeLoginSession::create([
            'company_id' => $this->e->company_id, 'employee_id' => $this->e->id, 'session_type' => 'CLIENT',
            'login_at' => '2026-07-06 09:20:00',
        ]);
        EmployeeAttendanceLog::create([
            'company_id' => $this->e->company_id, 'employee_id' => $this->e->id, 'work_date' => '2026-07-06',
            'source' => 'CLIENT', 'status' => 'PRESENT', 'check_in_at' => '2026-07-06 09:20:00',
        ]);
    }

    public function test_agent_gets_gate_in_and_sign_in_separately(): void
    {
        $token = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $device = $this->withToken($token)->postJson('/api/agent/register-device', ['device_uuid' => 'G-1', 'computer_name' => 'G'])->json('device_token');

        $t = $this->withToken($device)->getJson('/api/agent/today')->assertOk();
        $this->assertStringContainsString('09:05', (string) $t->json('gate_in_at'));
        $this->assertStringContainsString('09:20', (string) $t->json('logged_in_at'));
    }

    public function test_productivity_counts_the_day_from_the_door_with_gate_to_pc(): void
    {
        $row = collect($this->withToken($this->admin)->getJson('/api/reports/productivity?from=2026-07-06&to=2026-07-06')
            ->assertOk()->json('data'))->firstWhere('employee_code', $this->e->employee_code);

        $this->assertSame('09:05', $row['gate_in']);
        // Logged in stays the attendance check-in (EptFixesTest pins that).
        $this->assertSame(15 * 60, $row['gate_to_pc_seconds']);
        $this->assertSame((int) Carbon::parse('2026-07-06 09:05:00')->diffInSeconds(now(), true), $row['present_seconds'],
            'Actual Present runs from the door, not the PC');
    }
}
