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
class M13GateTest extends TestCase
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

    public function test_no_biometric_org_is_never_gated(): void
    {
        $g = $this->gate();
        $this->assertFalse($g['enabled']);
        $this->assertSame('IN', $g['state']); // credentials alone are enough
    }

    public function test_gate_auto_enables_with_device_and_lifts_on_in_punch(): void
    {
        $this->addPunchDevice();

        $g = $this->gate();
        $this->assertTrue($g['enabled']);
        $this->assertSame('OUT', $g['state']);
        $this->assertFalse($g['arrived']);

        $this->punch('IN', now()->subMinutes(5));

        $g = $this->gate();
        $this->assertSame('IN', $g['state']);
        $this->assertTrue($g['arrived']);

        // Continuous sync: the same block rides on every heartbeat.
        $hb = $this->withToken($this->deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'GATE-DEV-1',
        ])->assertOk();
        $this->assertSame('IN', $hb->json('gate.state'));
    }

    public function test_company_override_off_disables_gate_despite_device(): void
    {
        $this->addPunchDevice();

        $this->withToken($this->adminToken)->putJson('/api/companies/' . $this->employee->company_id, [
            'biometric_gate' => 'off',
        ])->assertOk();

        $this->assertFalse($this->gate()['enabled']);
    }

    public function test_midday_out_punch_opens_door_break_and_return_closes_it(): void
    {
        $this->addPunchDevice();
        $this->punch('IN', now()->subHours(2));

        EmployeeLoginSession::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'device_uuid' => 'GATE-DEV-1', 'login_at' => now()->subHours(2),
        ]);

        $this->punch('OUT', now()->subMinutes(10));

        $break = EmployeeBreakLog::where('employee_id', $this->employee->id)->whereNull('end_at')->first();
        $this->assertNotNull($break);
        $this->assertSame('BIOMETRIC', $break->source);

        $this->punch('IN', now());

        $break = $break->fresh();
        $this->assertNotNull($break->end_at);
        $this->assertSame(600, (int) $break->duration_seconds);
    }

    public function test_tiny_out_in_merges_away(): void
    {
        $this->addPunchDevice();
        $this->punch('IN', now()->subHour());
        EmployeeLoginSession::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'login_at' => now()->subHour(),
        ]);

        $this->punch('OUT', now()->subSeconds(90));
        $this->punch('IN', now());

        $this->assertSame(0, EmployeeBreakLog::where('employee_id', $this->employee->id)->count());
    }

    public function test_manual_break_is_upgraded_to_door_source(): void
    {
        $this->addPunchDevice();
        $this->punch('IN', now()->subHour());
        EmployeeLoginSession::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'login_at' => now()->subHour(),
        ]);

        $manual = EmployeeBreakLog::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'break_type' => 'TEA', 'source' => 'MANUAL', 'start_at' => now()->subMinutes(5),
        ]);

        $this->punch('OUT', now()->subMinutes(4));

        $this->assertSame('BIOMETRIC', $manual->fresh()->source);
        $this->assertSame(1, EmployeeBreakLog::where('employee_id', $this->employee->id)->count());
    }

    public function test_long_break_flags_compliance_and_very_long_mails_hr(): void
    {
        $this->addPunchDevice();
        $this->punch('IN', now()->subHours(6));
        EmployeeLoginSession::create([
            'company_id' => $this->employee->company_id, 'employee_id' => $this->employee->id,
            'login_at' => now()->subHours(6),
        ]);

        // 50-minute break → compliance event, no HR mail.
        $this->punch('OUT', now()->subHours(5));
        $this->punch('IN', now()->subHours(5)->addMinutes(50));

        $this->assertSame(1, EmployeeComplianceEvent::where('event_type', 'EXCESSIVE_DOOR_BREAK')->count());
        $this->assertSame(0, MailLog::where('kind', 'gate_long_break')->count());

        // 3.5-hour break → HIGH severity + HR email.
        $this->punch('OUT', now()->subHours(4));
        $this->punch('IN', now()->subMinutes(30));

        $events = EmployeeComplianceEvent::where('event_type', 'EXCESSIVE_DOOR_BREAK')->get();
        $this->assertSame(2, $events->count());
        $long = $events->firstWhere('detected_value', '210 min');
        $this->assertNotNull($long);
        $this->assertSame('HIGH', $long->severity);
        $this->assertGreaterThan(0, MailLog::where('kind', 'gate_long_break')->count());
    }
}
