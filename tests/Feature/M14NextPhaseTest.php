<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeMeetingSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Next development phase (21-Jul): breaks (Lunch/Tea/Other + limits + excess reason),
 * meetings (server-authorised join), and single-device login. API/model-level coverage
 * for Section 15. Agent-UI behaviours (popup, instant-idle, remember-me) are verified
 * manually per Section 15's manual checklist.
 */
class M14NextPhaseTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $userToken;
    private string $deviceToken;
    private Employee $employee;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(10));

        $this->adminToken = $this->login('admin@ametecs.io');
        $this->userToken = $this->login('priya.raman@ametecs.io');

        $this->deviceToken = $this->withToken($this->userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'M14-DEVICE', 'computer_name' => 'PRIYA-PC',
        ])->assertCreated()->json('device_token');

        // Consent so break/meeting events are accepted (they sit behind the consent gate).
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'M14-DEVICE', 'acknowledged' => true,
        ])->assertCreated();

        $this->employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();
        $this->companyId = $this->employee->company_id;
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    private function setBreakLimits(array $limits): void
    {
        $this->withToken($this->adminToken)->putJson('/api/companies/' . $this->companyId, $limits)->assertOk();
    }

    private function break(string $action, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->deviceToken)->postJson('/api/agent/break-event', array_merge([
            'device_uuid' => 'M14-DEVICE', 'action' => $action,
        ], $extra));
    }

    private function makeMeeting(bool $withPriya, $start, $end): int
    {
        return $this->withToken($this->adminToken)->postJson('/api/meetings', [
            'title'           => 'Sprint sync',
            'start_at'        => $start->toDateTimeString(),
            'end_at'          => $end->toDateTimeString(),
            'participant_ids' => $withPriya ? [$this->employee->id] : [],
        ])->assertCreated()->json('data.id');
    }

    private function meetingEvent(int $meetingId, string $action): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->deviceToken)->postJson('/api/agent/meeting-event', [
            'device_uuid' => 'M14-DEVICE', 'meeting_id' => $meetingId, 'action' => $action,
        ]);
    }

    // ---------- Section 1 & 3: breaks ----------

    public function test_break_over_limit_records_excess_and_reason(): void
    {
        $this->setBreakLimits(['break_limit_tea_min' => 1]); // 60s permitted

        $this->break('START', ['break_type' => 'TEA', 'occurred_at' => now()->subMinutes(5)->toDateTimeString()])->assertCreated();
        $this->break('END', ['occurred_at' => now()->toDateTimeString(), 'delay_reason' => 'Client call ran long'])->assertOk();

        $log = EmployeeBreakLog::where('employee_id', $this->employee->id)->latest('id')->first();
        $this->assertSame(60, (int) $log->permitted_seconds);
        $this->assertGreaterThan(0, (int) $log->excess_seconds);
        $this->assertSame('Client call ran long', $log->delay_reason);
        $this->assertSame('PENDING', $log->review_status);
    }

    public function test_break_within_limit_has_no_excess(): void
    {
        $this->setBreakLimits(['break_limit_lunch_min' => 30]);

        $this->break('START', ['break_type' => 'LUNCH', 'occurred_at' => now()->subMinute()->toDateTimeString()])->assertCreated();
        $this->break('END', ['occurred_at' => now()->toDateTimeString()])->assertOk();

        $log = EmployeeBreakLog::where('employee_id', $this->employee->id)->latest('id')->first();
        $this->assertSame(0, (int) $log->excess_seconds);
        $this->assertSame('NONE', $log->review_status);
    }

    public function test_break_limits_reach_the_agent_policy_bundle(): void
    {
        $this->setBreakLimits(['break_limit_tea_min' => 7]);

        $bundle = $this->withToken($this->deviceToken)
            ->getJson('/api/agent/policy?device_uuid=M14-DEVICE')->assertOk();

        $this->assertSame(420, $bundle->json('break_limits.TEA')); // 7 min in seconds
    }

    // ---------- Section 2: meetings ----------

    public function test_participant_can_join_meeting_inside_the_window(): void
    {
        $id = $this->makeMeeting(true, now()->subMinutes(5), now()->addMinutes(30));

        $this->meetingEvent($id, 'START')->assertCreated();
        $this->assertDatabaseHas('employee_meeting_sessions', [
            'meeting_id' => $id, 'employee_id' => $this->employee->id,
        ]);
        $this->meetingEvent($id, 'END')->assertOk();
    }

    public function test_non_participant_cannot_join(): void
    {
        $id = $this->makeMeeting(false, now()->subMinutes(5), now()->addMinutes(30));
        $this->meetingEvent($id, 'START')->assertStatus(403);
    }

    public function test_cannot_join_outside_the_scheduled_window(): void
    {
        $id = $this->makeMeeting(true, now()->subHours(2), now()->subHour());
        $this->meetingEvent($id, 'START')->assertStatus(422);
    }

    public function test_cancelled_meeting_cannot_be_joined(): void
    {
        $id = $this->makeMeeting(true, now()->subMinutes(5), now()->addMinutes(30));
        $this->withToken($this->adminToken)->postJson('/api/meetings/' . $id . '/cancel')->assertOk();
        $this->meetingEvent($id, 'START')->assertStatus(422);
    }

    public function test_employee_cannot_create_a_meeting(): void
    {
        $this->withToken($this->userToken)->postJson('/api/meetings', [
            'title' => 'Self meeting', 'start_at' => now()->toDateTimeString(),
            'end_at' => now()->addHour()->toDateTimeString(), 'participant_ids' => [$this->employee->id],
        ])->assertStatus(403);
    }

    public function test_meeting_time_is_recorded_and_is_not_a_break(): void
    {
        $id = $this->makeMeeting(true, now()->subMinutes(5), now()->addMinutes(30));
        $this->meetingEvent($id, 'START')->assertCreated();
        $this->travel(10)->minutes();
        $this->meetingEvent($id, 'END')->assertOk();

        $session = EmployeeMeetingSession::where('meeting_id', $id)->where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($session->actual_end_at);
        $this->assertGreaterThan(0, (int) $session->duration_seconds);

        // Meeting is NEVER a break.
        $this->assertSame(0, EmployeeBreakLog::where('employee_id', $this->employee->id)->count());

        // Company meeting report sees the attendance.
        $rows = $this->withToken($this->adminToken)->getJson('/api/reports/meetings')->assertOk()->json('data');
        $mine = collect($rows)->firstWhere('id', $id);
        $this->assertSame(1, $mine['attended']);
        $this->assertGreaterThan(0, $mine['actual_seconds']);
    }

    // ---------- Section 10: single-device login ----------

    public function test_second_pc_is_denied_while_the_first_is_active(): void
    {
        $token2 = $this->login('priya.raman@ametecs.io');
        $this->withToken($token2)->postJson('/api/agent/register-device', [
            'device_uuid' => 'M14-DEVICE-2', 'computer_name' => 'OTHER-PC',
        ])->assertStatus(409)->assertJsonPath('error.code', 'SINGLE_SESSION_ACTIVE');
    }

    public function test_same_device_restart_reconnects(): void
    {
        $token2 = $this->login('priya.raman@ametecs.io');
        $this->withToken($token2)->postJson('/api/agent/register-device', [
            'device_uuid' => 'M14-DEVICE', 'computer_name' => 'PRIYA-PC',
        ])->assertCreated();
    }

    public function test_stale_session_allows_takeover(): void
    {
        $this->travel(15)->minutes(); // past the 10-minute stale window
        $token2 = $this->login('priya.raman@ametecs.io');
        $this->withToken($token2)->postJson('/api/agent/register-device', [
            'device_uuid' => 'M14-DEVICE-2', 'computer_name' => 'OTHER-PC',
        ])->assertCreated();

        // The old device's session was retired.
        $old = EmployeeDevice::where('device_uuid', 'M14-DEVICE')->first();
        $this->assertSame('LOGGED_OUT', $old->session_status);
    }

    public function test_admin_force_logout_revokes_the_agent_session(): void
    {
        $device = EmployeeDevice::where('device_uuid', 'M14-DEVICE')->first();

        $this->withToken($this->adminToken)->postJson('/api/devices/' . $device->id . '/force-logout')->assertOk();

        $this->assertSame('FORCE_LOGOUT', $device->fresh()->session_status);
        // The agent token is dead → next heartbeat is unauthorised.
        $this->withToken($this->deviceToken)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'M14-DEVICE',
        ])->assertStatus(401);
    }

    // ---------- Section 9: biometric mapping ----------

    public function test_biometric_mapping_crud_and_one_employee_guard(): void
    {
        $map = fn (string $bio, array $extra = []) => $this->withToken($this->adminToken)
            ->postJson('/api/integrations/biometric/map-employee', array_merge([
                'biometric_employee_id' => $bio, 'employee_id' => $this->employee->id,
            ], $extra));

        $map('BIO-1')->assertCreated();

        $mappings = $this->withToken($this->adminToken)->getJson('/api/integrations/biometric/mappings')
            ->assertOk()->json('data');
        $this->assertContains('BIO-1', collect($mappings)->pluck('biometric_employee_id')->all());

        // Same employee, a different biometric id → warn (409) unless forced.
        $map('BIO-2')->assertStatus(409)->assertJsonPath('error.code', 'EMPLOYEE_ALREADY_MAPPED');
        $map('BIO-2', ['force' => true])->assertCreated();

        // Only the forced-in mapping is active now.
        $active = collect($this->withToken($this->adminToken)->getJson('/api/integrations/biometric/mappings')
            ->json('data'))->pluck('biometric_employee_id')->all();
        $this->assertContains('BIO-2', $active);
        $this->assertNotContains('BIO-1', $active);

        // Delete it.
        $id = collect($this->withToken($this->adminToken)->getJson('/api/integrations/biometric/mappings')->json('data'))
            ->firstWhere('biometric_employee_id', 'BIO-2')['id'];
        $this->withToken($this->adminToken)->deleteJson('/api/integrations/biometric/mappings/' . $id)->assertNoContent();
    }

    public function test_unmapped_biometric_ids_are_discoverable(): void
    {
        // A punch for an id with no mapping lands as unmapped.
        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/logs', [
            'logs' => [['biometric_employee_id' => 'BIO-UNMAPPED', 'punch_type' => 'IN', 'punched_at' => now()->toDateTimeString()]],
        ])->assertStatus(202);

        $unmapped = collect($this->withToken($this->adminToken)->getJson('/api/integrations/biometric/unmapped')
            ->assertOk()->json('data'))->pluck('biometric_employee_id')->all();

        $this->assertContains('BIO-UNMAPPED', $unmapped);
    }
}
