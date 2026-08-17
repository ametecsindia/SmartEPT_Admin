<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Services\GateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public punch ingest (/api/v1/attendance/punches) — the surface Smart Biometric
 * Bridge (SBB) delivers into. SBB is at-least-once and unattended, so every test
 * here is a defect that reached (or would have reached) a customer:
 *
 *  - a punch for a biometric-only employee 500'd the whole batch on an invalid
 *    enum value ('API' is not a member of the attendance source enum),
 *  - punches never reached biometric_logs, so Gate-to-PC locked out employees
 *    who had genuinely punched,
 *  - a late punch reopened a day HR had already closed,
 *  - a re-delivery was indistinguishable from a real second punch,
 *  - QA Phase 3 derivation (shift-aware checkout, configurable late) never ran
 *    on this path, so SBB-fed customers got PRESENT/ABSENT only.
 *
 * Pinned to a Wednesday 08:00 like QaPhase3Test, so the seeded MON–FRI General
 * shift (09:00–18:00, grace 10) always treats the day as a working day.
 */
class SbbPunchIngestTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;
    private Employee $employee;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(8, 0));
        $this->date = now()->toDateString();

        $this->employee = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $this->secret = $this->makeKey($this->employee->company_id);
    }

    private function makeKey(int $companyId, array $scopes = ['ingest', 'read'], ?string $expiresAt = null): string
    {
        $secret = 'sk_live_' . str_repeat((string) $companyId, 4) . uniqid();

        ApiKey::create([
            'company_id' => $companyId,
            'name' => 'SBB test key',
            'prefix' => substr($secret, 0, 12),
            'key_hash' => hash('sha256', $secret),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            'active' => true,
        ]);

        return $secret;
    }

    private function push(array $punches, ?string $secret = null)
    {
        return $this->withHeader('X-Api-Key', $secret ?? $this->secret)
            ->postJson('/api/v1/attendance/punches', ['punches' => $punches]);
    }

    private function at(string $time): string
    {
        return $this->date . ' ' . $time;
    }

    private function attendance(?int $employeeId = null)
    {
        return EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('employee_id', $employeeId ?? $this->employee->id)
            ->whereDate('work_date', $this->date)
            ->get();
    }

    // ---- FIX 1: the enum crash -------------------------------------------

    public function test_punch_creates_attendance_when_no_row_exists_yet(): void
    {
        // The branch that CREATES a row. It passes every demo (the PC agent has
        // already made one) and 500'd at a customer, for exactly the
        // biometric-only employees this integration exists to serve.
        $this->assertCount(0, $this->attendance());

        $res = $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:02:00')],
        ])->assertStatus(202);

        $this->assertSame(1, $res->json('accepted'));

        $rows = $this->attendance();
        $this->assertCount(1, $rows);
        $this->assertSame('BIOMETRIC', $rows[0]->source);   // a valid enum member
        $this->assertSame('PRESENT', $rows[0]->status);
        $this->assertSame('09:02:00', $rows[0]->check_in_at->format('H:i:s'));
    }

    // ---- FIX 2: the gate lockout -----------------------------------------

    public function test_ingested_punch_reaches_biometric_logs_and_opens_the_gate(): void
    {
        BiometricDevice::create([
            'company_id' => $this->employee->company_id,
            'name' => 'Main Gate', 'integration_method' => 'MIDDLEWARE_PUSH', 'status' => 'ACTIVE',
        ]);

        $gate = app(GateService::class);
        $this->assertFalse($gate->isOpen($this->employee->fresh()), 'gate must be shut before any punch');

        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00')],
        ])->assertStatus(202);

        $this->assertSame(1, BiometricLog::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)->count(), 'the punch must land in biometric_logs');
        $this->assertTrue($gate->isOpen($this->employee->fresh()), 'a genuine punch must lift the gate');
    }

    // ---- FIX 3: HR owns the day ------------------------------------------

    public function test_punch_does_not_modify_a_manual_row(): void
    {
        $manual = EmployeeAttendanceLog::create([
            'company_id' => $this->employee->company_id,
            'employee_id' => $this->employee->id,
            'work_date' => $this->date,
            'source' => 'MANUAL',
            'status' => 'PRESENT',
            'check_in_at' => $this->at('09:30:00'),
            'check_out_at' => $this->at('18:00:00'),
            'notes' => 'HR regularized',
        ]);

        $res = $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('08:15:00')],
            ['employee_code' => 'E-1001', 'punch_type' => 'OUT', 'punched_at' => $this->at('21:45:00')],
        ])->assertStatus(202);

        $manual->refresh();
        $this->assertSame('09:30:00', $manual->check_in_at->format('H:i:s'));
        $this->assertSame('18:00:00', $manual->check_out_at->format('H:i:s'));
        $this->assertCount(1, $this->attendance(), 'no shadow row may be created either');

        $skipped = $res->json('skipped_manual');
        $this->assertCount(1, $skipped);
        $this->assertSame('E-1001', $skipped[0]['employee_code']);
        $this->assertSame('MANUAL', $skipped[0]['existing_source']);
    }

    public function test_punch_does_not_reopen_an_on_leave_day(): void
    {
        $leave = EmployeeAttendanceLog::create([
            'company_id' => $this->employee->company_id,
            'employee_id' => $this->employee->id,
            'work_date' => $this->date,
            'source' => 'CLIENT',
            'status' => 'ON_LEAVE',
        ]);

        $res = $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:10:00')],
        ])->assertStatus(202);

        $this->assertNull($leave->fresh()->check_in_at);
        $this->assertCount(1, $res->json('skipped_manual'));
    }

    // ---- FIX 4: idempotency ----------------------------------------------

    public function test_same_external_id_twice_is_accepted_then_duplicate(): void
    {
        $punch = [
            'employee_code' => 'E-1001', 'punch_type' => 'IN',
            'punched_at' => $this->at('09:05:00'),
            'external_id' => hash('sha256', 'E-1001|IN|09:05'),
        ];

        $first = $this->push([$punch])->assertStatus(202);
        $this->assertSame('accepted', $first->json('punches.0.status'));
        $this->assertSame(0, $first->json('duplicates'));

        $second = $this->push([$punch])->assertStatus(202);
        $this->assertSame('duplicate', $second->json('punches.0.status'));
        $this->assertSame(1, $second->json('duplicates'));

        $this->assertSame(1, BiometricLog::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)->count(), 'a re-delivery must not create a second row');
    }

    public function test_external_id_over_96_chars_is_rejected(): void
    {
        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:05:00'),
             'external_id' => str_repeat('x', 97)],
        ])->assertStatus(422);
    }

    // ---- FIX 5: QA Phase 3 derivation runs on this path -------------------

    public function test_derivation_runs_and_sets_late_minutes(): void
    {
        // Seeded General shift: 09:00–18:00, 10 minutes grace. The exact minute
        // count is AttendanceDerivation's business (it honours the company's
        // late_arrival_source); what this proves is that derivation RAN AT ALL on
        // the public path — before this fix it never did, so every late-arrival
        // rule silently reported zero for SBB-fed customers.
        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:45:00')],
        ])->assertStatus(202);

        $row = $this->attendance()->first();
        $this->assertGreaterThan(0, (int) $row->late_minutes, 'a 09:45 arrival on a 09:00 shift must be late');
        $this->assertNotNull($row->check_in_source);
        $this->assertNotNull($row->arrival_source_used);
        $this->assertNotEmpty($row->derivation_note);
    }

    public function test_punch_inside_the_grace_window_is_not_late(): void
    {
        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:07:00')],
        ])->assertStatus(202);

        $this->assertSame(0, (int) $this->attendance()->first()->late_minutes);
    }

    public function test_a_midday_out_does_not_become_the_days_checkout(): void
    {
        // B7 shift-aware checkout, reached through the public path: IN … OUT … IN
        // means the OUT is a lunch break, not the end of the day.
        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN',  'punched_at' => $this->at('09:00:00')],
            ['employee_code' => 'E-1001', 'punch_type' => 'OUT', 'punched_at' => $this->at('13:00:00')],
            ['employee_code' => 'E-1001', 'punch_type' => 'IN',  'punched_at' => $this->at('13:40:00')],
        ])->assertStatus(202);

        $row = $this->attendance()->first();
        $this->assertTrue(
            $row->check_out_at === null || $row->check_out_at->format('H:i:s') !== '13:00:00',
            'a lunch OUT must never finalise the day at 13:00'
        );
    }

    // ---- FIX 6: the contract matches real hardware ------------------------

    public function test_break_punches_and_device_metadata_round_trip(): void
    {
        $this->push([
            ['employee_code' => 'e-1001', 'punch_type' => 'BREAK_OUT', 'punched_at' => $this->at('13:00:00'),
                'verification_mode' => 'FACE', 'source' => 'GATE-SN-7734',
                'direction_confidence' => 'NONE', 'device_status_raw' => '255'],
            ['employee_code' => 'E-1001', 'punch_type' => 'BREAK_IN', 'punched_at' => $this->at('13:30:00'),
                'verification_mode' => 'FINGERPRINT'],
        ])->assertStatus(202);

        $logs = BiometricLog::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)->orderBy('punched_at')->get();

        $this->assertCount(2, $logs, 'a lower-case employee_code must match too');
        $this->assertSame('BREAK_OUT', $logs[0]->punch_type);
        $this->assertSame('FACE', $logs[0]->verification_mode);
        $this->assertSame('GATE-SN-7734', $logs[0]->metadata['source']);
        $this->assertSame('NONE', $logs[0]->metadata['direction_confidence']);
        $this->assertSame('255', $logs[0]->metadata['device_status_raw']);
        $this->assertSame('BREAK_IN', $logs[1]->punch_type);
    }

    public function test_invalid_punch_type_and_verification_mode_are_rejected(): void
    {
        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'ENTER', 'punched_at' => $this->at('09:00:00')],
        ])->assertStatus(422);

        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00'),
             'verification_mode' => 'RETINA'],
        ])->assertStatus(422);
    }

    public function test_a_device_id_from_another_company_is_ignored_not_stored(): void
    {
        $other = Company::create(['name' => 'Other Corp', 'code' => 'OTHER-D', 'status' => 'ACTIVE']);
        $foreign = BiometricDevice::create([
            'company_id' => $other->id, 'name' => 'Their gate',
            'integration_method' => 'MIDDLEWARE_PUSH', 'status' => 'ACTIVE',
        ]);

        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00'),
             'device_id' => $foreign->id],
        ])->assertStatus(202);

        $log = BiometricLog::withoutGlobalScopes()->where('employee_id', $this->employee->id)->first();
        $this->assertNull($log->biometric_device_id, 'a cross-tenant device id must never be stored');
    }

    // ---- FIX 7: people who have left --------------------------------------

    public function test_relieved_and_deleted_employees_do_not_get_attendance(): void
    {
        $relieved = Employee::withoutGlobalScopes()->where('employee_code', 'E-1002')->firstOrFail();
        $relieved->update(['employment_status' => 'RELIEVED']);

        $deleted = Employee::withoutGlobalScopes()->where('employee_code', 'E-1003')->firstOrFail();
        $deleted->delete(); // soft delete

        $res = $this->push([
            ['employee_code' => 'E-1002', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00')],
            ['employee_code' => 'E-1003', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00')],
        ])->assertStatus(202);

        $this->assertEqualsCanonicalizing(['E-1002', 'E-1003'], $res->json('unknown_employee_codes'));
        $this->assertCount(0, $this->attendance($relieved->id));
        $this->assertCount(0, $this->attendance($deleted->id));
    }

    // ---- tenancy -----------------------------------------------------------

    public function test_a_key_from_company_a_cannot_write_a_punch_for_company_b(): void
    {
        $other = Company::create(['name' => 'Other Corp', 'code' => 'OTHER', 'status' => 'ACTIVE']);
        $victim = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'E-9001',
            'first_name' => 'Other', 'last_name' => 'Person', 'employment_status' => 'ACTIVE',
        ]);

        $res = $this->push([
            ['employee_code' => 'E-9001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00')],
        ])->assertStatus(202);

        $this->assertSame(['E-9001'], $res->json('unknown_employee_codes'));
        $this->assertCount(0, $this->attendance($victim->id));
        $this->assertSame(0, BiometricLog::withoutGlobalScopes()->where('company_id', $other->id)->count());
    }

    // ---- FIX 11: keys are no longer immortal -------------------------------

    public function test_an_expired_key_is_rejected(): void
    {
        $expired = $this->makeKey($this->employee->company_id, ['ingest', 'read'], now()->subDay()->toDateTimeString());

        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00')],
        ], $expired)->assertStatus(401);

        $this->assertCount(0, $this->attendance());
    }

    public function test_a_read_only_key_cannot_ingest(): void
    {
        $readOnly = $this->makeKey($this->employee->company_id, ['read']);

        $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00')],
        ], $readOnly)->assertStatus(403);
    }

    // ---- FIX 9 + FIX 12 + the roster ---------------------------------------

    public function test_ping_and_roster_give_an_installer_what_they_need(): void
    {
        $ping = $this->withHeader('X-Api-Key', $this->secret)->getJson('/api/v1/ping')->assertOk();
        $this->assertSame('Ametecs Pvt Ltd', $ping->json('company_name'));
        $this->assertNotNull($ping->json('timezone'));
        $this->assertNotNull($ping->json('licence.state'));

        $roster = $this->withHeader('X-Api-Key', $this->secret)->getJson('/api/v1/employees')->assertOk();
        $this->assertSame(3, $roster->json('count'));
        $this->assertContains('E-1001', array_column($roster->json('data'), 'employee_code'));
    }

    public function test_ingest_response_reports_the_licence_verdict(): void
    {
        $res = $this->push([
            ['employee_code' => 'E-1001', 'punch_type' => 'IN', 'punched_at' => $this->at('09:00:00')],
        ])->assertStatus(202);

        $this->assertNotNull($res->json('licence.state'));
        $this->assertNotNull($res->json('licence.message'));
    }
}
