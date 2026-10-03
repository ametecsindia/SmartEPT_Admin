<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeLoginSession;
use App\Models\MailLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Biometric Gate (Doc 11 v1.1 — Ejaz 16-Jul): gate follows the org's device
 * setup, punch state syncs continuously (gate-status + heartbeat), and mid-day
 * OUT punches drive the auto-break engine (merge / flag / HR mail).
 */
class GateLockWalkOutTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $deviceToken;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(10)); // stable "today"

        $this->adminToken = $this->login('admin@ametecs.io');

        $userToken = $this->login('priya.raman@ametecs.io');
        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'GATE-DEV-1', 'computer_name' => 'GATE-PC',
        ])->assertCreated()->json('device_token');

        $this->employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    private function addPunchDevice(): void
    {
        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/devices', [
            'name' => 'Main Gate', 'integration_method' => 'MIDDLEWARE_PUSH', 'status' => 'ACTIVE',
        ])->assertCreated();

        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/map-employee', [
            'biometric_employee_id' => 'BIO-77', 'employee_id' => $this->employee->id,
        ])->assertCreated();
    }

    private function punch(string $type, $at): void
    {
        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/logs', [
            'logs' => [['biometric_employee_id' => 'BIO-77', 'punch_type' => $type, 'punched_at' => $at->toDateTimeString()]],
        ])->assertStatus(202);
    }

    private function gate(): array
    {
        return $this->withToken($this->deviceToken)->getJson('/api/agent/gate-status')->assertOk()->json('gate');
    }

    /** 28-Sep-2026 (tiru): lock the PC, walk out — must be a door break, not lost time. */
    public function test_lock_then_walk_out_opens_a_door_break(): void
    {
        $this->addPunchDevice();
        $this->punch('IN', now()->subHours(2));
        EmployeeLoginSession::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'device_uuid' => 'GATE-DEV-1', 'login_at' => now()->subHours(2),
            'logout_at' => now()->subMinutes(12), 'logout_reason' => 'LOCK',
        ]);

        $this->punch('OUT', now()->subMinutes(10));
        $break = EmployeeBreakLog::where('employee_id', $this->employee->id)->whereNull('end_at')->first();
        $this->assertNotNull($break, 'a LOCK is not a sign-out — the walk-out is a break');

        $this->punch('IN', now());
        $this->assertSame(600, (int) $break->fresh()->duration_seconds);
    }

    public function test_walk_out_after_a_real_sign_out_is_still_not_a_break(): void
    {
        $this->addPunchDevice();
        $this->punch('IN', now()->subHours(2));
        EmployeeLoginSession::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'device_uuid' => 'GATE-DEV-1', 'login_at' => now()->subHours(2),
            'logout_at' => now()->subMinutes(12), 'logout_reason' => 'USER',
        ]);

        $this->punch('OUT', now()->subMinutes(10));
        $this->assertSame(0, EmployeeBreakLog::where('employee_id', $this->employee->id)->count());
    }

    /** 28-Sep-2026: back from a door punch-out with no Break → heartbeat carries the notice. */
    public function test_heartbeat_reports_away_time_after_return(): void
    {
        // Punch times are the site's wall clock; align the org clock with the test clock.
        \App\Models\Company::withoutGlobalScopes()->whereKey($this->employee->company_id)
            ->update(['timezone' => config('app.timezone')]);
        $this->addPunchDevice();
        $this->punch('IN', now()->subHours(2));
        EmployeeLoginSession::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'device_uuid' => 'GATE-DEV-1', 'login_at' => now()->subHours(2),
        ]);
        $this->punch('OUT', now()->subMinutes(40));
        $this->punch('IN', now()->subMinutes(5));

        $n = $this->withToken($this->deviceToken)->postJson('/api/agent/heartbeat', ['device_uuid' => 'GATE-DEV-1'])
            ->assertOk()->json('away_notice');
        $this->assertNotNull($n);
        $this->assertSame(35, $n['minutes']);

        // A Break the employee started is never reported as away.
        EmployeeBreakLog::query()->update(['device_uuid' => 'GATE-DEV-1']);
        $this->assertNull($this->withToken($this->deviceToken)->postJson('/api/agent/heartbeat', ['device_uuid' => 'GATE-DEV-1'])
            ->assertOk()->json('away_notice'));
    }
}
