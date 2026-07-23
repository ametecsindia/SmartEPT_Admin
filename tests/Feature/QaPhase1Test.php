<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\StatusTimeline;
use App\Services\StatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA Phase 1 — the authoritative status timeline + state machine (Part C; fixes
 * A4/A6/A7/B1/B2/B3/B15). Covers the StatusService invariants directly, the break↔meeting
 * exclusivity (D1) through the agent endpoints, and the live-dashboard categorisation.
 */
class QaPhase1Test extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'M-QA1';

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
            'device_uuid' => self::DEVICE, 'computer_name' => 'PRIYA-PC',
        ])->assertCreated()->json('device_token');

        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => self::DEVICE, 'acknowledged' => true,
        ])->assertCreated();

        $this->employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    private function svc(): StatusService
    {
        return app(StatusService::class);
    }

    private function break(string $action, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->deviceToken)->postJson('/api/agent/break-event', array_merge([
            'device_uuid' => self::DEVICE, 'action' => $action,
        ], $extra));
    }

    private function makeMeeting($start, $end): int
    {
        return $this->withToken($this->adminToken)->postJson('/api/meetings', [
            'title'           => 'QA sync',
            'start_at'        => $start->toDateTimeString(),
            'end_at'          => $end->toDateTimeString(),
            'participant_ids' => [$this->employee->id],
        ])->assertCreated()->json('data.id');
    }

    private function meetingEvent(int $meetingId, string $action): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->deviceToken)->postJson('/api/agent/meeting-event', [
            'device_uuid' => self::DEVICE, 'meeting_id' => $meetingId, 'action' => $action,
        ]);
    }

    private function openSegments(): \Illuminate\Support\Collection
    {
        return StatusTimeline::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)->whereNull('ended_at')->get();
    }

    private function allSegments(): \Illuminate\Support\Collection
    {
        return StatusTimeline::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)->get();
    }

    // ---------- StatusService invariants ----------

    public function test_only_one_segment_is_ever_open(): void
    {
        $e = $this->employee;
        $this->svc()->transition($e, 'ACTIVE', now());
        $this->svc()->transition($e, 'IDLE', now()->addMinute());
        $this->svc()->transition($e, 'ACTIVE', now()->addMinutes(2));

        $this->assertCount(1, $this->openSegments());
        $this->assertSame('ACTIVE', $this->openSegments()->first()->state);
        $this->assertSame(3, $this->allSegments()->count());
    }

    public function test_repeated_event_uuid_is_idempotent(): void
    {
        $e = $this->employee;
        $first = $this->svc()->transition($e, 'ACTIVE', now(), ['event_uuid' => 'evt-1']);
        $again = $this->svc()->transition($e, 'IDLE', now()->addMinute(), ['event_uuid' => 'evt-1']);

        $this->assertTrue($first->changed);
        $this->assertTrue($again->deduped);
        $this->assertSame(1, $this->allSegments()->count());
        $this->assertSame('ACTIVE', $this->svc()->currentState($e));
    }

    public function test_meeting_is_productive_and_never_a_break(): void
    {
        $e = $this->employee;
        $this->svc()->transition($e, 'MEETING', now(), ['manual' => true, 'meeting_id' => 1]);
        $this->svc()->resumeActive($e, now()->addMinutes(5));

        $totals = $this->svc()->dayTotals($e->id, now()->toDateString());
        $this->assertSame(300, $totals['meeting']);
        $this->assertSame(0, $totals['break_total']);
    }

    public function test_lock_forces_idle_over_meeting_but_respects_a_break(): void
    {
        $e = $this->employee;

        // A lock during a meeting → you are idle (you stepped away).
        $this->svc()->transition($e, 'MEETING', now(), ['manual' => true, 'meeting_id' => 1]);
        $this->svc()->forceIdle($e, now()->addMinute(), 'LOCK');
        $this->assertSame('IDLE', $this->svc()->currentState($e));

        // A lock during a manual break → you are still on that break.
        $this->svc()->closeAll($e, now()->addMinutes(2));
        $this->svc()->transition($e, 'TEA_BREAK', now()->addMinutes(3), ['manual' => true]);
        $this->svc()->forceIdle($e, now()->addMinutes(4), 'LOCK');
        $this->assertSame('TEA_BREAK', $this->svc()->currentState($e));
    }

    public function test_logout_closes_all_segments(): void
    {
        $e = $this->employee;
        $this->svc()->transition($e, 'ACTIVE', now());
        $this->svc()->closeAll($e, now()->addMinute());

        $this->assertSame('OFFLINE', $this->svc()->currentState($e));
        $this->assertCount(0, $this->openSegments());
    }

    public function test_ambient_activity_does_not_clobber_a_manual_break(): void
    {
        $e = $this->employee;
        $this->svc()->transition($e, 'TEA_BREAK', now(), ['manual' => true]);
        // A background ACTIVE/IDLE stretch arriving mid-break must be ignored.
        $this->svc()->transition($e, 'ACTIVE', now()->addMinute());
        $this->svc()->transition($e, 'IDLE', now()->addMinutes(2));

        $this->assertSame('TEA_BREAK', $this->svc()->currentState($e));
    }

    // ---------- Exclusivity (D1) through the agent endpoints ----------

    public function test_manual_tea_then_lunch_is_rejected_409(): void
    {
        $this->break('START', ['break_type' => 'TEA'])->assertCreated();
        $this->break('START', ['break_type' => 'LUNCH'])
            ->assertStatus(409)->assertJsonPath('error.code', 'STATUS_CONFLICT');
    }

    public function test_break_then_meeting_is_rejected_409(): void
    {
        $mid = $this->makeMeeting(now()->subMinutes(5), now()->addMinutes(30));
        $this->break('START', ['break_type' => 'TEA'])->assertCreated();
        $this->meetingEvent($mid, 'START')
            ->assertStatus(409)->assertJsonPath('error.code', 'STATUS_CONFLICT');
    }

    public function test_meeting_then_break_is_rejected_409(): void
    {
        $mid = $this->makeMeeting(now()->subMinutes(5), now()->addMinutes(30));
        $this->meetingEvent($mid, 'START')->assertCreated();
        $this->break('START', ['break_type' => 'TEA'])
            ->assertStatus(409)->assertJsonPath('error.code', 'STATUS_CONFLICT');
    }

    public function test_ending_a_break_returns_to_active_in_the_timeline(): void
    {
        $this->break('START', ['break_type' => 'TEA'])->assertCreated();
        $this->assertSame('TEA_BREAK', $this->svc()->currentState($this->employee));

        $this->break('END')->assertOk();
        $this->assertSame('ACTIVE', $this->svc()->currentState($this->employee));
    }

    // ---------- Live dashboard categorisation (B1/B2/B3/B15) ----------

    public function test_dashboard_places_each_employee_in_exactly_one_category(): void
    {
        // Priya goes on a tea break, and her device looks online (fresh heartbeat).
        $this->break('START', ['break_type' => 'TEA'])->assertCreated();
        EmployeeDevice::where('device_uuid', self::DEVICE)
            ->update(['last_heartbeat_at' => now(), 'current_status' => 'ONLINE']);

        $res = $this->withToken($this->adminToken)->getJson('/api/dashboard/live-status')->assertOk();
        $c = $res->json('cards');

        // The break widgets are live.
        $this->assertGreaterThanOrEqual(1, $c['break_total']);
        $this->assertGreaterThanOrEqual(1, $c['break_tea']);

        // Every employee falls into exactly one working bucket → the buckets sum to headcount.
        $sum = $c['active'] + $c['idle'] + $c['break_total'] + $c['meeting'] + $c['offline_count'];
        $this->assertSame($c['total_employees'], $sum);

        // Priya's row is on the break, with a start time the filtered list can show.
        $mine = collect($res->json('employees'))->firstWhere('employee_id', $this->employee->id);
        $this->assertSame('TEA_BREAK', $mine['work_status']);
        $this->assertNotNull($mine['break_started_at']);
    }

    public function test_today_reports_meeting_time_and_the_break_split(): void
    {
        // A five-minute tea break…
        $this->break('START', ['break_type' => 'TEA', 'occurred_at' => now()->subMinutes(5)->toDateTimeString()])->assertCreated();
        $this->break('END', ['occurred_at' => now()->toDateTimeString()])->assertOk();

        // …then a three-minute meeting.
        $mid = $this->makeMeeting(now()->subMinutes(10), now()->addMinutes(30));
        $this->meetingEvent($mid, 'START')->assertCreated();
        $this->travel(3)->minutes();
        $this->meetingEvent($mid, 'END')->assertOk();

        $today = $this->withToken($this->deviceToken)->getJson('/api/agent/today')->assertOk();
        $this->assertSame(300, $today->json('break_tea_seconds'));
        $this->assertSame(180, $today->json('meeting_seconds'));
    }
}
