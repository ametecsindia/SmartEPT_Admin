<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDailySummary;
use App\Models\EmployeeLoginSession;
use App\Models\Holiday;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Release-1 items 2+3: holiday calendar, working-day awareness, nightly attendance
 * completion (auto-absent / half-day / stale sessions), manual regularization,
 * biometric→attendance merge, and the monthly payroll pack.
 *
 * Fixed 2026 dates are used with travelTo (like M2/M6 freeze midday) so weekday
 * logic never depends on when the suite runs. Seeded GEN shift: 09:00–18:00,
 * working_days MON–FRI. 2026-07-05 = SUN, 2026-07-06 = MON.
 */
class M8AttendanceCompletenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): string
    {
        return $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
    }

    private function employee(string $code): Employee
    {
        return Employee::withoutGlobalScopes()->where('employee_code', $code)->firstOrFail();
    }

    // ---- A. Holiday calendar ----

    public function test_holiday_crud_and_tenant_scoping(): void
    {
        $token = $this->admin();

        // Seeded 2026 calendar is visible.
        $list = $this->withToken($token)->getJson('/api/holidays?year=2026')->assertOk();
        $names = collect($list->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Independence Day'));
        $this->assertCount(3, $list->json('data'));

        // Create + duplicate-date guard (tenant-scoped unique).
        $created = $this->withToken($token)->postJson('/api/holidays', [
            'holiday_date' => '2026-12-25', 'name' => 'Christmas', 'type' => 'PUBLIC',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/holidays', [
            'holiday_date' => '2026-12-25', 'name' => 'Duplicate',
        ])->assertStatus(422);

        // Another tenant's holiday is neither listed nor deletable.
        $company2 = Company::create(['name' => 'Other Corp', 'code' => 'OTHER', 'timezone' => 'Asia/Kolkata', 'status' => 'ACTIVE']);
        $foreign = Holiday::create(['company_id' => $company2->id, 'holiday_date' => '2026-12-31', 'name' => 'Foreign Day']);

        $ids = collect($this->withToken($token)->getJson('/api/holidays?year=2026')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($foreign->id));
        $this->withToken($token)->deleteJson('/api/holidays/' . $foreign->id)->assertNotFound();

        $this->withToken($token)->deleteJson('/api/holidays/' . $created->json('data.id'))->assertNoContent();
    }

    // ---- C. Nightly attendance completion ----

    public function test_mark_attendance_creates_absent_only_on_working_days(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 12:00:00'));

        // Sunday (not in MON–FRI shift roster) → nothing marked.
        $this->artisan('smartept:mark-attendance', ['--date' => '2026-07-05'])->assertExitCode(0);
        $this->assertSame(0, EmployeeAttendanceLog::withoutGlobalScopes()->whereDate('work_date', '2026-07-05')->count());

        // Monday → every ACTIVE employee without a row is auto-marked ABSENT.
        $this->artisan('smartept:mark-attendance', ['--date' => '2026-07-06'])->assertExitCode(0);
        $rows = EmployeeAttendanceLog::withoutGlobalScopes()->whereDate('work_date', '2026-07-06')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['ABSENT'], $rows->pluck('status')->unique()->all());
        $this->assertSame(['MANUAL'], $rows->pluck('source')->unique()->all());
        $this->assertSame('Auto-marked absent', $rows->first()->notes);

        // Company holiday (Tuesday) → also nothing marked.
        Holiday::create(['company_id' => 1, 'holiday_date' => '2026-07-07', 'name' => 'Founders Day', 'type' => 'COMPANY']);
        $this->artisan('smartept:mark-attendance', ['--date' => '2026-07-07'])->assertExitCode(0);
        $this->assertSame(0, EmployeeAttendanceLog::withoutGlobalScopes()->whereDate('work_date', '2026-07-07')->count());
    }

    public function test_stale_sessions_auto_close_with_capped_duration(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 12:00:00'));
        $e1 = $this->employee('E-1001'); // GEN shift ends 18:00
        $e2 = $this->employee('E-1002');
        $e2->update(['shift_id' => null]); // no shift → close at 23:59:59

        $s1 = EmployeeLoginSession::create([
            'company_id' => 1, 'employee_id' => $e1->id, 'session_type' => 'CLIENT', 'login_at' => '2026-07-06 09:00:00',
        ]);
        $s2 = EmployeeLoginSession::create([
            'company_id' => 1, 'employee_id' => $e2->id, 'session_type' => 'CLIENT', 'login_at' => '2026-07-06 00:30:00',
        ]);

        $this->artisan('smartept:mark-attendance', ['--date' => '2026-07-07'])->assertExitCode(0);

        $s1->refresh();
        $this->assertSame('2026-07-06 18:00:00', $s1->logout_at->toDateTimeString()); // login day's shift end
        $this->assertSame(9 * 3600, (int) $s1->duration_seconds);
        $this->assertSame('AUTO_CLOSED', $s1->logout_reason);

        $s2->refresh();
        $this->assertSame('2026-07-06 23:59:59', $s2->logout_at->toDateTimeString());
        $this->assertSame(16 * 3600, (int) $s2->duration_seconds); // 23.5h capped at 16h
        $this->assertSame('AUTO_CLOSED', $s2->logout_reason);
    }

    public function test_short_day_is_downgraded_to_half_day(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 12:00:00'));
        $short = $this->employee('E-1001');
        $full = $this->employee('E-1002');

        foreach ([$short, $full] as $e) {
            EmployeeAttendanceLog::create([
                'company_id' => 1, 'employee_id' => $e->id, 'work_date' => '2026-07-06',
                'source' => 'CLIENT', 'status' => 'PRESENT', 'check_in_at' => '2026-07-06 09:00:00',
            ]);
        }
        // 2h session < half of the 9h shift (16200s) → HALF_DAY; 6h stays PRESENT.
        EmployeeLoginSession::create([
            'company_id' => 1, 'employee_id' => $short->id, 'session_type' => 'CLIENT',
            'login_at' => '2026-07-06 09:00:00', 'logout_at' => '2026-07-06 11:00:00', 'duration_seconds' => 7200,
        ]);
        EmployeeLoginSession::create([
            'company_id' => 1, 'employee_id' => $full->id, 'session_type' => 'CLIENT',
            'login_at' => '2026-07-06 09:00:00', 'logout_at' => '2026-07-06 15:00:00', 'duration_seconds' => 21600,
        ]);

        $this->artisan('smartept:mark-attendance', ['--date' => '2026-07-06'])->assertExitCode(0);

        $status = fn ($e) => EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('employee_id', $e->id)->whereDate('work_date', '2026-07-06')->value('status');
        $this->assertSame('HALF_DAY', $status($short));
        $this->assertSame('PRESENT', $status($full));
    }

    // ---- D. Manual regularization ----

    public function test_manual_regularization_put_requires_reason_and_updates_row(): void
    {
        $token = $this->admin();
        $row = EmployeeAttendanceLog::create([
            'company_id' => 1, 'employee_id' => 1, 'work_date' => '2026-07-06',
            'source' => 'MANUAL', 'status' => 'ABSENT', 'notes' => 'Auto-marked absent',
        ]);

        // No reason → 422: payroll corrections must be justified.
        $this->withToken($token)->putJson('/api/attendance/' . $row->id, ['status' => 'ON_LEAVE'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->withToken($token)->putJson('/api/attendance/' . $row->id, [
            'status' => 'ON_LEAVE', 'reason' => 'Approved sick leave',
        ])->assertOk();

        $row->refresh();
        $this->assertSame('ON_LEAVE', $row->status);
        $this->assertSame('MANUAL', $row->source);
        $this->assertStringContainsString('Approved sick leave', $row->notes);
        $this->assertStringContainsString('admin@ametecs.io', $row->notes); // actor stamped into the trail
        $this->assertTrue(
            AuditLog::where('action', 'UPDATE')->where('subject_type', EmployeeAttendanceLog::class)
                ->where('subject_id', $row->id)->exists()
        );
    }

    public function test_manual_post_creates_missed_day_and_guards_duplicates(): void
    {
        $token = $this->admin();
        $payload = ['employee_id' => 1, 'work_date' => '2026-07-09', 'status' => 'ON_LEAVE', 'reason' => 'Leave never synced'];

        $this->withToken($token)->postJson('/api/attendance', $payload)->assertCreated();
        $row = EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', 1)->whereDate('work_date', '2026-07-09')->first();
        $this->assertSame('ON_LEAVE', $row->status);
        $this->assertSame('MANUAL', $row->source);

        // Same employee+date again → 422, regardless of source.
        $this->withToken($token)->postJson('/api/attendance', $payload)->assertStatus(422);

        // Reason is mandatory on create too.
        $this->withToken($token)->postJson('/api/attendance', [
            'employee_id' => 1, 'work_date' => '2026-07-10', 'status' => 'PRESENT',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        // Tenant-scoped exists rule: another company's employee is rejected.
        $company2 = Company::create(['name' => 'Other Corp', 'code' => 'OTHER', 'timezone' => 'Asia/Kolkata', 'status' => 'ACTIVE']);
        $foreign = Employee::create([
            'company_id' => $company2->id, 'employee_code' => 'X-1', 'first_name' => 'Xeno', 'employment_status' => 'ACTIVE',
        ]);
        $this->withToken($token)->postJson('/api/attendance', [
            'employee_id' => $foreign->id, 'work_date' => '2026-07-09', 'status' => 'PRESENT', 'reason' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    // ---- E. Biometric → attendance merge ----

    public function test_biometric_ingest_creates_and_merges_attendance(): void
    {
        $this->travelTo(Carbon::parse('2026-07-06 20:00:00'));
        $token = $this->admin();

        // No prior row → BIOMETRIC PRESENT row with first-IN / last-OUT punch times.
        $this->withToken($token)->postJson('/api/integrations/biometric/map-employee', [
            'biometric_employee_id' => 'BIO-9001', 'employee_id' => 1,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/integrations/biometric/logs', ['logs' => [
            ['biometric_employee_id' => 'BIO-9001', 'punch_type' => 'IN', 'punched_at' => '2026-07-06 09:05:00'],
            ['biometric_employee_id' => 'BIO-9001', 'punch_type' => 'OUT', 'punched_at' => '2026-07-06 17:30:00'],
        ]])->assertStatus(202);

        $row = EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', 1)->whereDate('work_date', '2026-07-06')->first();
        $this->assertSame('PRESENT', $row->status);
        $this->assertSame('BIOMETRIC', $row->source);
        $this->assertSame('2026-07-06 09:05:00', $row->check_in_at->toDateTimeString());
        $this->assertSame('2026-07-06 17:30:00', $row->check_out_at->toDateTimeString());

        // Existing agent row with an EARLIER check-in: biometric never overwrites it,
        // but a later OUT punch extends the day (earliest-in / latest-out wins).
        $agent = EmployeeAttendanceLog::create([
            'company_id' => 1, 'employee_id' => 2, 'work_date' => '2026-07-06',
            'source' => 'CLIENT', 'status' => 'PRESENT', 'check_in_at' => '2026-07-06 08:50:00',
        ]);
        $this->withToken($token)->postJson('/api/integrations/biometric/map-employee', [
            'biometric_employee_id' => 'BIO-9002', 'employee_id' => 2,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/integrations/biometric/logs', ['logs' => [
            ['biometric_employee_id' => 'BIO-9002', 'punch_type' => 'IN', 'punched_at' => '2026-07-06 09:10:00'],
            ['biometric_employee_id' => 'BIO-9002', 'punch_type' => 'OUT', 'punched_at' => '2026-07-06 18:10:00'],
        ]])->assertStatus(202);

        $agent->refresh();
        $this->assertSame('2026-07-06 08:50:00', $agent->check_in_at->toDateTimeString());
        $this->assertSame('2026-07-06 18:10:00', $agent->check_out_at->toDateTimeString());
        // And no second row was created for the day.
        $this->assertSame(1, EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', 2)->whereDate('work_date', '2026-07-06')->count());
    }

    // ---- F. Monthly payroll pack ----

    public function test_monthly_summary_math(): void
    {
        $this->travelTo(Carbon::parse('2026-07-31 12:00:00'));
        $token = $this->admin();

        foreach ([
            ['2026-07-06', 'PRESENT'], ['2026-07-07', 'PRESENT'], ['2026-07-08', 'HALF_DAY'],
            ['2026-07-09', 'ON_LEAVE'], ['2026-07-10', 'ABSENT'],
        ] as [$date, $status]) {
            EmployeeAttendanceLog::create([
                'company_id' => 1, 'employee_id' => 1, 'work_date' => $date, 'source' => 'MANUAL', 'status' => $status,
            ]);
        }
        foreach ([['2026-07-06', 20000, 5, 80], ['2026-07-07', 10000, 10, 60]] as [$date, $active, $late, $score]) {
            EmployeeDailySummary::create([
                'company_id' => 1, 'employee_id' => 1, 'work_date' => $date,
                'active_seconds' => $active, 'late_minutes' => $late, 'productivity_score' => $score,
            ]);
        }

        $res = $this->withToken($token)->getJson('/api/reports/monthly-summary?month=2026-07')->assertOk();
        $row = collect($res->json('data'))->firstWhere('employee_id', 1);

        $this->assertSame(23, $row['working_days_in_month']); // 31 days − 4 Sat − 4 Sun, no July holidays
        $this->assertSame(2, $row['present']);
        $this->assertSame(1, $row['half_day']);
        $this->assertSame(1, $row['on_leave']);
        $this->assertSame(1, $row['absent']);
        $this->assertSame(0, $row['holidays_count']);
        $this->assertSame(30000, $row['total_active_seconds']);
        $this->assertSame(15, $row['total_late_minutes']);
        $this->assertEqualsWithDelta(70.0, $row['avg_productivity_score'], 0.01);
        $this->assertEqualsWithDelta(3.5, $row['payable_days'], 0.001); // 2 + 0.5*1 + 1
    }

    public function test_attendance_register_csv_matrix(): void
    {
        $this->travelTo(Carbon::parse('2026-07-31 12:00:00'));
        $token = $this->admin();

        Holiday::create(['company_id' => 1, 'holiday_date' => '2026-07-20', 'name' => 'Founders Day', 'type' => 'COMPANY']);
        EmployeeAttendanceLog::create(['company_id' => 1, 'employee_id' => 1, 'work_date' => '2026-07-06', 'source' => 'MANUAL', 'status' => 'PRESENT']);
        EmployeeAttendanceLog::create(['company_id' => 1, 'employee_id' => 1, 'work_date' => '2026-07-07', 'source' => 'MANUAL', 'status' => 'ABSENT']);

        $res = $this->withToken($token)->get('/api/export/attendance-register?month=2026-07')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $lines = array_map('str_getcsv', array_filter(explode("\n", $res->streamedContent())));
        $header = $lines[0];
        $this->assertSame('Payable Days', end($header));
        $row = collect($lines)->firstWhere(fn ($l) => $l[0] === 'E-1001');
        $this->assertNotNull($row);

        $day = fn (int $d) => $row[2 + $d]; // code, name, team, then day 1..31
        $this->assertSame('WO', $day(5));   // Sunday weekly off
        $this->assertSame('P', $day(6));    // present
        $this->assertSame('A', $day(7));    // absent
        $this->assertSame('HD', $day(20));  // company holiday
        $this->assertSame('-', $day(1));    // working day, no data

        // Totals: Present, Absent, Half, Leave, WO, Holidays, Payable Days.
        $totals = array_slice($row, 3 + 31);
        $this->assertSame(['1', '1', '0', '0', '8', '1', '1'], $totals);
    }

    public function test_productivity_and_daily_summary_exports_accept_ranges(): void
    {
        $token = $this->admin();

        // Legacy single date= still works.
        $this->withToken($token)->get('/api/export/productivity?date=2026-07-06')->assertOk();
        // New from/to range.
        $res = $this->withToken($token)->get('/api/export/productivity?from=2026-07-01&to=2026-07-31')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        EmployeeDailySummary::create([
            'company_id' => 1, 'employee_id' => 1, 'work_date' => '2026-07-06',
            'active_seconds' => 1000, 'productivity_score' => 50,
        ]);
        $res = $this->withToken($token)->get('/api/export/daily-summary?from=2026-07-01&to=2026-07-31')->assertOk();
        $this->assertStringContainsString('E-1001', $res->streamedContent());
        $this->withToken($token)->get('/api/export/daily-summary?date=2026-07-06')->assertOk();
    }
}
