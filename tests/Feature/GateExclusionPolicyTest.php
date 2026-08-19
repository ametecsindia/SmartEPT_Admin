<?php

namespace Tests\Feature;

use App\Models\Employee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GATE-TO-PC EXCLUSION POLICY (Ejaz, 18-Aug-2026).
 *
 * "If Gate-to-PC is enabled and I want to skip it — allow login without biometric —
 * for a branch or team or particular selected employees, is there that exclusion
 * policy?" There wasn't. This proves the one that now exists.
 *
 * gate_mode is tri-state on every level: NULL inherits, EXCLUDED frees, REQUIRED
 * claws an exclusion back for a sub-group. Precedence, most specific first:
 * DEVICE > EMPLOYEE > TEAM > DEPARTMENT > BRANCH.
 */
class GateExclusionPolicyTest extends TestCase
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
            'device_uuid' => 'EXCL-DEV-1', 'computer_name' => 'EXCL-PC',
        ])->assertCreated()->json('device_token');

        $this->employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();

        // Put the company behind the gate, the way a biometric customer runs.
        $this->withToken($this->adminToken)->putJson('/api/companies/' . $this->employee->company_id, [
            'biometric_gate' => 'on',
        ])->assertOk();
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    /** The agent's own view of the gate. */
    private function gate(): array
    {
        return $this->withToken($this->deviceToken)->getJson('/api/agent/gate-status')->assertOk()->json();
    }

    /** Set gate_mode on one org level and return the employee's resulting gate state. */
    private function setOrgGateMode(string $type, int $id, ?string $mode, array $extra = []): void
    {
        $this->withToken($this->adminToken)
            ->putJson("/api/org/{$type}/{$id}", ['gate_mode' => $mode] + $extra)
            ->assertOk();
    }

    public function test_baseline_gated_company_walls_the_agent(): void
    {
        $g = $this->gate();
        $this->assertTrue($g['gate_required']);
        $this->assertFalse($g['open']);
        $this->assertSame('NO_PUNCH', $g['reason']);
        $this->assertFalse($g['excluded']);
    }

    public function test_employee_level_exclusion_frees_only_that_person(): void
    {
        $colleague = Employee::where('company_id', $this->employee->company_id)
            ->where('id', '!=', $this->employee->id)->firstOrFail();

        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
        ])->assertOk();

        $g = $this->gate();
        $this->assertFalse($g['gate_required']);
        $this->assertTrue($g['open']);
        $this->assertTrue($g['excluded']);
        $this->assertSame('EMPLOYEE', $g['excluded_level']);

        // The colleague is untouched — an exclusion is not a company-wide off switch.
        $trace = $this->withToken($this->adminToken)
            ->getJson('/api/employees/' . $colleague->id . '/gate-trace')->assertOk()->json('data');
        $this->assertTrue($trace['effective']['gate_required']);
        $this->assertFalse($trace['effective']['open']);
    }

    public function test_branch_level_exclusion_covers_everyone_in_the_branch(): void
    {
        $this->assertNotNull($this->employee->branch_id, 'seed employee should sit in a branch');

        $this->setOrgGateMode('branches', $this->employee->branch_id, 'EXCLUDED');

        $g = $this->gate();
        $this->assertTrue($g['open']);
        $this->assertSame('BRANCH', $g['excluded_level']);
    }

    public function test_team_required_claws_back_a_branch_exclusion(): void
    {
        $this->assertNotNull($this->employee->team_id, 'seed employee should sit in a team');

        $this->setOrgGateMode('branches', $this->employee->branch_id, 'EXCLUDED');
        $this->assertTrue($this->gate()['open']);

        // The security team inside an excluded branch must still punch in.
        $this->setOrgGateMode('teams', $this->employee->team_id, 'REQUIRED');

        $g = $this->gate();
        $this->assertTrue($g['gate_required']);
        $this->assertFalse($g['open']);
        $this->assertFalse($g['excluded']);
    }

    public function test_employee_exclusion_beats_a_team_requirement(): void
    {
        $this->setOrgGateMode('teams', $this->employee->team_id, 'REQUIRED');
        $this->assertFalse($this->gate()['open']);

        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
        ])->assertOk();

        $this->assertTrue($this->gate()['open']); // most specific level wins
    }

    public function test_excluded_employee_can_actually_start_a_work_session(): void
    {
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'EXCL-DEV-1', 'acknowledged' => true,
        ])->assertSuccessful();

        // Gated: refused.
        $this->withToken($this->deviceToken)->postJson('/api/agent/attendance-event', [
            'device_uuid' => 'EXCL-DEV-1', 'event_type' => 'LOGIN',
        ])->assertStatus(423);

        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
        ])->assertOk();

        // Excluded: the session starts on credentials alone — no door punch, no override.
        $this->withToken($this->deviceToken)->postJson('/api/agent/attendance-event', [
            'device_uuid' => 'EXCL-DEV-1', 'event_type' => 'LOGIN',
        ])->assertSuccessful();
    }

    public function test_device_level_exclusion_applies_to_one_machine(): void
    {
        $device = \App\Models\EmployeeDevice::where('device_uuid', 'EXCL-DEV-1')->firstOrFail();

        $this->withToken($this->adminToken)
            ->putJson('/api/devices/' . $device->id . '/gate-mode', ['gate_mode' => 'EXCLUDED'])
            ->assertOk();

        // attendance-event carries device_uuid, so the DEVICE level resolves there.
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'EXCL-DEV-1', 'acknowledged' => true,
        ])->assertSuccessful();

        $this->withToken($this->deviceToken)->postJson('/api/agent/attendance-event', [
            'device_uuid' => 'EXCL-DEV-1', 'event_type' => 'LOGIN',
        ])->assertSuccessful();
    }

    public function test_null_gate_mode_returns_the_employee_to_the_gate(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
        ])->assertOk();
        $this->assertTrue($this->gate()['open']);

        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => null,
        ])->assertOk();

        $this->assertFalse($this->gate()['open']); // back to inheriting
    }

    public function test_exclusion_is_irrelevant_when_the_company_has_no_gate(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/companies/' . $this->employee->company_id, [
            'biometric_gate' => 'off',
        ])->assertOk();

        $g = $this->gate();
        $this->assertFalse($g['gate_required']);
        $this->assertTrue($g['open']);
        $this->assertFalse($g['excluded']); // not excluded — there was nothing to exclude from
    }

    public function test_gate_trace_explains_the_decision_to_an_admin(): void
    {
        $this->setOrgGateMode('branches', $this->employee->branch_id, 'EXCLUDED');

        $trace = $this->withToken($this->adminToken)
            ->getJson('/api/employees/' . $this->employee->id . '/gate-trace')
            ->assertOk()->json('data');

        $this->assertTrue($trace['company_gate_enabled']);
        $this->assertTrue($trace['exclusion']['excluded']);
        $this->assertSame('BRANCH', $trace['exclusion']['level']);
        $this->assertTrue($trace['effective']['open']);
    }

    public function test_gate_mode_rejects_a_bogus_value(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'MAYBE',
        ])->assertStatus(422);
    }

    // ---- Dated exclusions (Ejaz, 18-Aug-2026) ----------------------------------------
    // "The reader is down 20–22 Aug" / "her finger won't read this week" must expire on
    // their own. An exclusion nobody remembers to remove is how the control quietly dies.

    public function test_exclusion_inside_its_window_applies(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
            'gate_mode_from' => now()->subDay()->toDateString(),
            'gate_mode_until' => now()->addDays(3)->toDateString(),
            'gate_mode_reason' => 'Fingerprint not reading — replacement enrolment booked.',
        ])->assertOk();

        $g = $this->gate();
        $this->assertTrue($g['open']);
        $this->assertTrue($g['excluded']);
        $this->assertSame(now()->addDays(3)->toDateString(), $g['excluded_until']);
        $this->assertStringContainsString('Fingerprint', $g['exclusion_reason']);
    }

    public function test_exclusion_has_not_started_yet(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
            'gate_mode_from' => now()->addDays(2)->toDateString(),
            'gate_mode_until' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $this->assertFalse($this->gate()['open']); // booked for next week, not live today
    }

    public function test_exclusion_expires_by_itself_with_no_admin_action(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
            'gate_mode_from' => now()->toDateString(),
            'gate_mode_until' => now()->addDays(2)->toDateString(),
        ])->assertOk();

        $this->assertTrue($this->gate()['open']);

        // Midday UTC on the last day is still that same day in Asia/Kolkata (the seeded
        // tenant's timezone), so the exclusion is live.
        $this->travelTo(now()->addDays(2)->setTime(12, 0));
        $this->assertTrue($this->gate()['open']);

        $this->travelTo(now()->addDay()->setTime(12, 0));   // the day after
        $this->assertFalse($this->gate()['open'], 'the gate must re-arm itself once the window passes');
    }

    public function test_a_dead_reader_can_be_covered_at_branch_level_for_three_days(): void
    {
        // The reader at this branch is dead — nobody there can punch in until Friday.
        $this->setOrgGateMode('branches', $this->employee->branch_id, 'EXCLUDED', [
            'gate_mode_from' => now()->toDateString(),
            'gate_mode_until' => now()->addDays(2)->toDateString(),
            'gate_mode_reason' => 'Door controller RMA — engineer booked Friday.',
        ]);

        $g = $this->gate();
        $this->assertTrue($g['open']);
        $this->assertSame('BRANCH', $g['excluded_level']);

        $this->travelTo(now()->addDays(3)->setTime(9, 0)); // reader is back
        $this->assertFalse($this->gate()['open']);
    }

    public function test_expired_level_falls_through_to_the_level_above(): void
    {
        // Branch excluded permanently, employee REQUIRED but only until yesterday.
        $this->setOrgGateMode('branches', $this->employee->branch_id, 'EXCLUDED');
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'REQUIRED',
            'gate_mode_until' => now()->subDay()->toDateString(),
        ])->assertOk();

        // The employee's claw-back has lapsed, so the branch exclusion governs again.
        $g = $this->gate();
        $this->assertTrue($g['open']);
        $this->assertSame('BRANCH', $g['excluded_level']);
    }

    public function test_until_before_from_is_rejected(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
            'gate_mode_from' => now()->addDays(5)->toDateString(),
            'gate_mode_until' => now()->addDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_the_granting_admin_is_recorded_and_cleared_with_the_exclusion(): void
    {
        $admin = \App\Models\User::where('email', 'admin@ametecs.io')->firstOrFail();

        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED', 'gate_mode_reason' => 'Night shift, no reader on that door.',
        ])->assertOk();

        $this->assertSame($admin->id, $this->employee->fresh()->gate_mode_by_user_id);

        // Clearing the exclusion clears the attribution and the window with it.
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => null,
        ])->assertOk();

        $fresh = $this->employee->fresh();
        $this->assertNull($fresh->gate_mode_by_user_id);
        $this->assertNull($fresh->gate_mode_reason);
    }

    public function test_a_required_level_is_never_reported_as_an_exclusion(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'REQUIRED',
        ])->assertOk();

        $g = $this->gate();
        $this->assertFalse($g['excluded']);
        $this->assertNull($g['excluded_level'], 'a REQUIRED level must not be labelled as excluding');
    }

    // ---- The Gate Exclusions screen's roll-up ----------------------------------------

    public function test_the_console_can_list_every_exclusion_in_one_place(): void
    {
        $this->setOrgGateMode('branches', $this->employee->branch_id, 'EXCLUDED', [
            'gate_mode_until' => now()->addDays(2)->toDateString(),
            'gate_mode_reason' => 'Site link down — punches stuck on the device.',
        ]);
        $this->setOrgGateMode('teams', $this->employee->team_id, 'REQUIRED');
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED', 'gate_mode_from' => now()->addWeek()->toDateString(),
        ])->assertOk();

        $r = $this->withToken($this->adminToken)->getJson('/api/gate-exclusions')->assertOk();
        $rows = collect($r->json('data'));

        $this->assertCount(3, $rows);
        $this->assertTrue($r->json('meta.gate_enabled'));

        $branch = $rows->firstWhere('level', 'BRANCH');
        $this->assertSame('EXCLUDED', $branch['gate_mode']);
        $this->assertSame('ACTIVE', $branch['status']);
        $this->assertStringContainsString('Site link down', $branch['reason']);
        $this->assertNotNull($branch['granted_by'], 'the granting admin must be shown');

        $this->assertSame('REQUIRED', $rows->firstWhere('level', 'TEAM')['gate_mode']);
        // Booked for next week — the screen must call that out rather than imply it is live.
        $this->assertSame('SCHEDULED', $rows->firstWhere('level', 'EMPLOYEE')['status']);
    }

    public function test_the_roll_up_marks_a_lapsed_exclusion_as_expired(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
            'gate_mode_from' => now()->subDays(5)->toDateString(),
            'gate_mode_until' => now()->subDay()->toDateString(),
        ])->assertOk();

        $rows = $this->withToken($this->adminToken)->getJson('/api/gate-exclusions')->assertOk()->json('data');

        $this->assertSame('EXPIRED', $rows[0]['status']);
        $this->assertFalse($this->gate()['open'], 'an expired row must not still be lifting the gate');
    }

    // ---- Second review round -----------------------------------------------------

    public function test_the_window_expires_on_the_tenants_midnight_not_utcs(): void
    {
        // Asia/Kolkata is UTC+5:30, so 20:00 UTC on the 22nd is already 01:30 on the 23rd
        // for the customer. An exclusion dated "until the 22nd" must be dead by then —
        // evaluating it in UTC left it lifting the gate through the first 5h30m of every
        // night shift.
        \App\Models\Company::withoutGlobalScopes()->whereKey($this->employee->company_id)
            ->update(['timezone' => 'Asia/Kolkata']);
        \App\Models\Branch::withoutGlobalScopes()->whereKey($this->employee->branch_id)
            ->update(['timezone' => null]);

        $until = \Illuminate\Support\Carbon::now('Asia/Kolkata')->addDay()->toDateString();

        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED', 'gate_mode_until' => $until,
        ])->assertOk();

        $this->assertTrue($this->gate()['open']);

        // 20:00 UTC on the last day = 01:30 local the NEXT day → the window has passed.
        $this->travelTo(\Illuminate\Support\Carbon::parse($until . ' 20:00:00', 'UTC'));
        $this->assertFalse($this->gate()['open'], 'the window must end at the tenant\'s midnight, not UTC\'s');

        // …and the console agrees, so the screen never contradicts enforcement.
        $rows = $this->withToken($this->adminToken)->getJson('/api/gate-exclusions')->assertOk()->json('data');
        $this->assertSame('EXPIRED', $rows[0]['status']);
    }

    public function test_extending_an_exclusion_reattributes_it_and_keeps_the_dates(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED', 'gate_mode_until' => now()->addDay()->toDateString(),
        ])->assertOk();

        // A window-only PUT (no gate_mode key at all) must NOT be treated as a removal,
        // and must re-record who made the change.
        $hr = \App\Models\User::where('email', 'hr@ametecs.io')->firstOrFail();
        $hrToken = $this->login('hr@ametecs.io'); // a real token: withToken() headers persist
                                                  // across requests on the test instance.

        $this->withToken($hrToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode_until' => now()->addWeek()->toDateString(),
        ])->assertOk();

        $fresh = $this->employee->fresh();
        $this->assertSame('EXCLUDED', $fresh->gate_mode, 'a window-only PUT must not drop the exclusion');
        $this->assertSame(now()->addWeek()->toDateString(),
            \Illuminate\Support\Carbon::parse($fresh->gate_mode_until)->toDateString());
        $this->assertSame($hr->id, $fresh->gate_mode_by_user_id,
            'whoever extended it is the one now accountable for it');
    }

    public function test_an_unbound_machine_no_longer_walls_an_excluded_employee(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
        ])->assertOk();

        $device = \App\Models\EmployeeDevice::where('device_uuid', 'EXCL-DEV-1')->firstOrFail();
        $this->withToken($this->adminToken)->putJson('/api/devices/' . $device->id . '/gate-mode', [
            'gate_mode' => 'REQUIRED',
        ])->assertOk();
        $this->assertFalse($this->gate()['open']); // the live machine still gates them

        // Retire that machine. A PC that no longer exists must not keep someone walled.
        \App\Models\EmployeeDevice::withoutGlobalScopes()->whereKey($device->id)
            ->update(['unbound_at' => now()]);

        $this->assertTrue($this->gate()['open']);
    }

    // ---- Defects found in adversarial review of the first cut ------------------------

    public function test_a_soft_deleted_branch_stops_granting_its_exclusion(): void
    {
        $this->setOrgGateMode('branches', $this->employee->branch_id, 'EXCLUDED');
        $this->assertTrue($this->gate()['open']);

        $this->withToken($this->adminToken)
            ->deleteJson('/api/org/branches/' . $this->employee->branch_id)->assertSuccessful();

        $this->assertFalse($this->gate()['open'], 'deleting the branch must re-gate its staff');
    }

    public function test_a_device_moved_to_another_company_loses_its_exclusion(): void
    {
        $device = \App\Models\EmployeeDevice::where('device_uuid', 'EXCL-DEV-1')->firstOrFail();

        $this->withToken($this->adminToken)->putJson('/api/devices/' . $device->id . '/gate-mode', [
            'gate_mode' => 'EXCLUDED', 'gate_mode_reason' => 'Kiosk by the loading bay.',
        ])->assertOk();

        // Simulate the laptop having last been registered under a DIFFERENT tenant, which
        // is exactly the state register() sees when a machine changes hands. device_uuid is
        // agent-supplied and globally unique, so this is reachable on purpose as well as by
        // accident — client A's admin must not be able to lift client B's gate.
        $foreign = \App\Models\Company::create(['name' => 'Other Corp', 'code' => 'OTHER-GATE', 'status' => 'ACTIVE']);
        \App\Models\EmployeeDevice::withoutGlobalScopes()->whereKey($device->id)
            ->update(['company_id' => $foreign->id]);

        // The original employee's agent re-registers the same machine.
        $userToken = $this->login('priya.raman@ametecs.io');
        $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'EXCL-DEV-1', 'computer_name' => 'MOVED-PC',
        ])->assertSuccessful();

        $moved = $device->fresh();
        $this->assertSame($this->employee->company_id, $moved->company_id, 'the device should have moved tenant');
        $this->assertNull($moved->gate_mode, "a reassigned machine must not carry the previous tenant's exclusion");
        $this->assertNull($moved->gate_mode_reason);

        // …and the gate is genuinely back up for that machine.
        $this->assertFalse(app(\App\Services\GateService::class)->exclusionFor($this->employee->fresh(), $moved)['excluded']);
    }

    public function test_the_bare_gate_poll_does_not_lift_the_wall_for_a_required_machine(): void
    {
        // Person is excluded, but one of their machines is explicitly still gated.
        $this->withToken($this->adminToken)->putJson('/api/employees/' . $this->employee->id, [
            'gate_mode' => 'EXCLUDED',
        ])->assertOk();

        $device = \App\Models\EmployeeDevice::where('device_uuid', 'EXCL-DEV-1')->firstOrFail();
        $this->withToken($this->adminToken)->putJson('/api/devices/' . $device->id . '/gate-mode', [
            'gate_mode' => 'REQUIRED',
        ])->assertOk();

        // The agent's plain poll names no device — it must answer for the strictest case
        // rather than dropping the wall on a PC an admin deliberately kept gated.
        $this->assertFalse($this->gate()['open']);

        // And the enforcing route, which does name the device, agrees.
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'EXCL-DEV-1', 'acknowledged' => true,
        ])->assertSuccessful();
        $this->withToken($this->deviceToken)->postJson('/api/agent/attendance-event', [
            'device_uuid' => 'EXCL-DEV-1', 'event_type' => 'LOGIN',
        ])->assertStatus(423);
    }
}
