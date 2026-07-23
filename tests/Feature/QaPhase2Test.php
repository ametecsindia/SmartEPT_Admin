<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA Phase 2 (server side) — gate-status shape + reason codes (A3), the emergency gate
 * override + agent tamper log (A3/A8), first/last login (A2/A1), and the exit-lock in the
 * policy bundle (A8).
 */
class QaPhase2Test extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $deviceToken;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(10));

        $this->adminToken = $this->login('admin@ametecs.io');
        $userToken = $this->login('priya.raman@ametecs.io');

        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'QA2-DEV', 'computer_name' => 'QA2-PC',
        ])->assertCreated()->json('device_token');

        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'QA2-DEV', 'acknowledged' => true,
        ])->assertCreated();

        $this->employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    private function gateStatus(): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->deviceToken)->getJson('/api/agent/gate-status')->assertOk();
    }

    private function addPunchDevice(): void
    {
        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/devices', [
            'name' => 'Main Gate', 'integration_method' => 'MIDDLEWARE_PUSH', 'status' => 'ACTIVE',
        ])->assertCreated();
        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/map-employee', [
            'biometric_employee_id' => 'BIO-QA2', 'employee_id' => $this->employee->id,
        ])->assertCreated();
    }

    // ---- A3: gate-status shape + reason ----

    public function test_gate_status_returns_both_shapes_when_not_gated(): void
    {
        $res = $this->gateStatus();
        // Backward-compatible nested block (console + heartbeat consumers).
        $this->assertFalse($res->json('gate.enabled'));
        // NEW top-level fields the agent enforces on.
        $this->assertFalse($res->json('gate_required'));
        $this->assertTrue($res->json('open'));
        $this->assertNull($res->json('reason'));
    }

    public function test_gate_status_gives_a_reason_when_closed(): void
    {
        $this->addPunchDevice(); // gate now required, no punch yet

        $res = $this->gateStatus();
        $this->assertTrue($res->json('gate_required'));
        $this->assertFalse($res->json('open'));
        $this->assertSame('NO_PUNCH', $res->json('reason'));
    }

    // ---- A3/A8: emergency override + tamper log ----

    public function test_admin_gate_override_lifts_the_gate_and_is_logged(): void
    {
        $this->addPunchDevice();
        $this->assertFalse($this->gateStatus()->json('open')); // closed before override

        $this->withToken($this->adminToken)->postJson('/api/agent-override/gate', [
            'employee_id' => $this->employee->id,
            'reason'      => 'Door reader offline — verified arrival in person.',
            'device_uuid' => 'QA2-DEV',
        ])->assertOk()->assertJsonPath('gate.open', true);

        $this->assertTrue($this->gateStatus()->json('open')); // lifted
        $this->assertDatabaseHas('agent_tamper_events', [
            'employee_id' => $this->employee->id, 'event_type' => 'GATE_OVERRIDE', 'outcome' => 'GRANTED',
        ]);
    }

    public function test_employee_cannot_override_the_gate(): void
    {
        $userToken = $this->login('priya.raman@ametecs.io');
        $this->withToken($userToken)->postJson('/api/agent-override/gate', [
            'employee_id' => $this->employee->id, 'reason' => 'let me in',
        ])->assertStatus(403);
    }

    public function test_agent_reports_a_tamper_attempt(): void
    {
        $this->withToken($this->deviceToken)->postJson('/api/agent/tamper-attempt', [
            'device_uuid' => 'QA2-DEV',
            'event_type'  => 'EXIT_ATTEMPT',
            'outcome'     => 'FAILED',
            'reason'      => 'wrong password x3',
        ])->assertCreated();

        $this->assertDatabaseHas('agent_tamper_events', [
            'employee_id' => $this->employee->id, 'event_type' => 'EXIT_ATTEMPT', 'outcome' => 'FAILED',
        ]);
    }

    // ---- A1/A2: first login is write-once; today never goes blank ----

    public function test_first_login_is_write_once_and_today_stays_stable(): void
    {
        $login = fn () => $this->withToken($this->deviceToken)->postJson('/api/agent/attendance-event', [
            'device_uuid' => 'QA2-DEV', 'event_type' => 'LOGIN',
        ])->assertCreated();

        $login();
        $first = $this->withToken($this->deviceToken)->getJson('/api/agent/today')->assertOk()->json('logged_in_at');
        $this->assertNotNull($first);

        $this->travel(20)->minutes();
        $login(); // re-login later in the day

        $second = $this->withToken($this->deviceToken)->getJson('/api/agent/today')->assertOk()->json('logged_in_at');
        $this->assertSame($first, $second, 'logged_in_at must stay pinned to the first login');

        $att = EmployeeAttendanceLog::where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($att->first_login_at);
        $this->assertNotNull($att->last_login_at);
    }

    // ---- A8: exit lock reaches the agent policy bundle (SHA-256 only) ----

    public function test_exit_lock_is_exposed_in_the_policy_bundle_as_a_hash(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/ops/agent-lock', [
            'enabled' => true, 'password' => 'letmeout9',
        ])->assertOk();

        $bundle = $this->withToken($this->deviceToken)
            ->getJson('/api/agent/policy?device_uuid=QA2-DEV')->assertOk();

        $this->assertTrue($bundle->json('agent.exit_lock_enabled'));
        $this->assertSame(hash('sha256', 'letmeout9'), $bundle->json('agent.exit_password_sha256'));
    }
}
