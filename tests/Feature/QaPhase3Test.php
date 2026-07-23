<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * QA Phase 3 — biometric & attendance derivation (B7 shift-aware checkout, B8/D2
 * configurable late) and A3 gate enforcement on activity/screenshot ingestion.
 *
 * Pinned to a Wednesday 08:00 so the seeded MON–FRI General shift (09:00–18:00,
 * grace 10) always treats the day as a working day.
 */
class QaPhase3Test extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $deviceToken;
    private Employee $employee;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // startOfWeek = Monday; +2 days = Wednesday. 08:00 so a 09:00 shift start is ahead.
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(8, 0));
        $this->date = now()->toDateString();

        $this->adminToken = $this->login('admin@ametecs.io');
        $userToken = $this->login('priya.raman@ametecs.io');

        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'QA3-DEV', 'computer_name' => 'QA3-PC',
        ])->assertCreated()->json('device_token');

        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'QA3-DEV', 'acknowledged' => true,
        ])->assertCreated();

        $this->employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    /** Register an ACTIVE punch reader + map the employee → the gate becomes active. */
    private function addPunchDevice(): void
    {
        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/devices', [
            'name' => 'Main Gate', 'integration_method' => 'MIDDLEWARE_PUSH', 'status' => 'ACTIVE',
        ])->assertCreated();
        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/map-employee', [
            'biometric_employee_id' => 'BIO-QA3', 'employee_id' => $this->employee->id,
        ])->assertCreated();
    }

    private function pushPunch(string $type, string $hms): void
    {
        $this->withToken($this->adminToken)->postJson('/api/integrations/biometric/logs', [
            'logs' => [[
                'biometric_employee_id' => 'BIO-QA3',
                'punch_type'            => $type,
                'punched_at'            => $this->date . ' ' . $hms,
            ]],
        ])->assertStatus(202);
    }

    private function attendance(): ?EmployeeAttendanceLog
    {
        return EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->whereDate('work_date', $this->date)
            ->first();
    }

    // ---- B7: intermediate OUT is not a checkout; delayed punch recomputes ----

    public function test_lunch_out_is_not_checkout_and_final_out_recomputes(): void
    {
        $this->addPunchDevice();

        // Morning in, lunch out, back from lunch — still inside, no completed checkout.
        $this->pushPunch('IN',  '09:00:00');
        $this->pushPunch('OUT', '13:00:00');
        $this->pushPunch('IN',  '14:00:00');

        $att = $this->attendance();
        $this->assertNotNull($att);
        $this->assertSame('09:00', $att->check_in_at?->format('H:i'), 'check-in is the first IN');
        $this->assertNull($att->check_out_at, 'a lunch OUT followed by a return IN must NOT be a checkout');

        // End-of-day OUT arrives later → checkout recomputes to the trailing OUT.
        $this->pushPunch('OUT', '18:00:00');

        $att = $this->attendance();
        $this->assertSame('18:00', $att->check_out_at?->format('H:i'), 'checkout is the last (trailing) OUT');
        $this->assertSame('BIOMETRIC', $att->check_out_source);
    }

    // ---- Manual attendance is never overwritten by raw punches ----

    public function test_manual_attendance_is_preserved(): void
    {
        $manual = EmployeeAttendanceLog::create([
            'company_id'   => $this->employee->company_id,
            'employee_id'  => $this->employee->id,
            'work_date'    => $this->date,
            'source'       => 'MANUAL',
            'status'       => 'PRESENT',
            'check_in_at'  => $this->date . ' 10:00:00',
            'check_out_at' => $this->date . ' 16:00:00',
        ]);

        $this->addPunchDevice();
        $this->pushPunch('IN',  '09:00:00');
        $this->pushPunch('OUT', '18:00:00');

        $fresh = $manual->fresh();
        $this->assertSame('MANUAL', $fresh->source);
        $this->assertSame('10:00', $fresh->check_in_at?->format('H:i'), 'manual check-in untouched');
        $this->assertSame('16:00', $fresh->check_out_at?->format('H:i'), 'manual check-out untouched');
    }

    // ---- B8/D2: configurable late, per source ----

    public function test_biometric_only_employee_gets_late_default_later_of_both(): void
    {
        // Company default late_arrival_source = LATER_OF_BOTH; an active reader gates the day,
        // so the door IN is the effective arrival for a biometric-only employee.
        $this->addPunchDevice();
        $this->pushPunch('IN', '09:30:00');

        $att = $this->attendance();
        // permitted = 09:00 + 10 grace = 09:10 → 20 minutes late.
        $this->assertSame(20, (int) $att->late_minutes);
        $this->assertSame('LATER_OF_BOTH', $att->arrival_source_used);
    }

    public function test_late_honours_biometric_in_source(): void
    {
        Company::query()->update(['late_arrival_source' => 'BIOMETRIC_IN']);

        $this->addPunchDevice();
        $this->pushPunch('IN', '09:25:00');

        $att = $this->attendance();
        $this->assertSame(15, (int) $att->late_minutes); // 09:25 − 09:10
        $this->assertSame('BIOMETRIC_IN', $att->arrival_source_used);
    }

    // ---- Re-login never moves the late value (first arrival wins) ----

    public function test_relogin_does_not_change_late(): void
    {
        // No biometric device → gate disabled → agent LOGIN is allowed and is the arrival.
        $login = fn (string $hms) => $this->withToken($this->deviceToken)->postJson('/api/agent/attendance-event', [
            'device_uuid' => 'QA3-DEV', 'event_type' => 'LOGIN', 'occurred_at' => $this->date . ' ' . $hms,
        ])->assertCreated();

        $login('09:40:00');
        $first = (int) $this->attendance()->late_minutes;
        $this->assertSame(30, $first); // 09:40 − 09:10 permitted

        $login('10:15:00'); // re-login later in the day
        $this->assertSame($first, (int) $this->attendance()->late_minutes, 'late must stay pinned to the first arrival');
    }

    // ---- A3: gate closed refuses activity + screenshot ingestion ----

    public function test_gate_closed_refuses_activity_and_screenshot_capture(): void
    {
        $this->addPunchDevice(); // gate now required, no IN punch yet → closed

        $this->withToken($this->deviceToken)->postJson('/api/agent/activity-events', [
            'device_uuid' => 'QA3-DEV',
            'events'      => [['event_type' => 'ACTIVE', 'started_at' => now()->toDateTimeString()]],
        ])->assertStatus(423);

        $this->withToken($this->deviceToken)->postJson('/api/agent/screenshot-upload', [
            'device_uuid' => 'QA3-DEV',
            'image'       => UploadedFile::fake()->image('shot.jpg', 100, 100),
        ])->assertStatus(423);
    }

    public function test_gate_open_allows_activity_capture(): void
    {
        $this->addPunchDevice();
        $this->pushPunch('IN', '09:00:00'); // door open → capture allowed

        $this->withToken($this->deviceToken)->postJson('/api/agent/activity-events', [
            'device_uuid' => 'QA3-DEV',
            'events'      => [['event_type' => 'ACTIVE', 'started_at' => $this->date . ' 09:05:00']],
        ])->assertStatus(202);
    }
}
