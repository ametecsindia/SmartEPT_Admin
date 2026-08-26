<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeDailySummary;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 26-Aug-2026 (Ejaz): "The entire login hours should match the overall time calculation" and
 * "ensure that even a single minute is not missed".
 *
 * Builds ONE fully-known historical day and asserts the report reproduces it exactly — not a
 * formula replay, the real endpoint, through the real EmployeeDailySummary path that the client's
 * broken export came from.
 *
 * Also the regression test for the date-cast bug: the summary casts work_date to a date, so
 * (string) $summary->work_date was "2026-07-07 00:00:00" and every break / meeting / time-out
 * lookup — keyed on MySQL DATE() = "2026-07-07" — missed. That printed 0 breaks next to a
 * non-zero break total, which is exactly what the client reported.
 */
class ProductivityReportReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-07';   // a Tuesday; seeded GEN shift is 09:00–18:00 MON–FRI

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
    }

    private function token(): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'password',
        ])->json('token');
    }

    /**
     * A day that balances exactly: 09:00 → 18:00 is 9h, made of 6h active + 2h idle + 1h break.
     * Every bucket is a round number so any drift shows up immediately.
     */
    private function buildKnownDay(): Employee
    {
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $day = self::DAY;

        EmployeeAttendanceLog::create([
            'company_id' => 1, 'employee_id' => $e->id, 'work_date' => $day,
            'source' => 'CLIENT', 'status' => 'PRESENT',
            'check_in_at' => "$day 09:00:00", 'check_out_at' => "$day 18:00:00",
            'late_minutes' => 12,
        ]);

        EmployeeActivityEvent::create(['company_id' => 1, 'employee_id' => $e->id,
            'event_type' => 'ACTIVE', 'started_at' => "$day 09:00:00", 'duration_seconds' => 6 * 3600]);
        EmployeeActivityEvent::create(['company_id' => 1, 'employee_id' => $e->id,
            'event_type' => 'IDLE', 'started_at' => "$day 15:00:00", 'duration_seconds' => 2 * 3600]);

        // Two breaks totalling one hour — the count is what the client's sheet printed as 0.
        EmployeeBreakLog::create(['company_id' => 1, 'employee_id' => $e->id, 'break_type' => 'LUNCH',
            'start_at' => "$day 13:00:00", 'end_at' => "$day 13:45:00", 'duration_seconds' => 45 * 60]);
        EmployeeBreakLog::create(['company_id' => 1, 'employee_id' => $e->id, 'break_type' => 'TEA',
            'start_at' => "$day 16:00:00", 'end_at' => "$day 16:15:00", 'duration_seconds' => 15 * 60]);

        // Inserted raw, NOT through the model: MySQL holds work_date as a real DATE
        // ("2026-07-07"), while the model's date cast would write "2026-07-07 00:00:00" on
        // sqlite. Writing it the way production stores it is what makes this a faithful test
        // of the date-cast bug — the model still READS it back as a Carbon, which is where
        // (string) $summary->work_date produced the broken lookup key.
        \Illuminate\Support\Facades\DB::table('employee_daily_summaries')->insert([
            'company_id' => 1, 'employee_id' => $e->id, 'work_date' => $day,
            'present_seconds' => 8 * 3600,          // active + idle, as ScoringService computes it
            'active_seconds' => 6 * 3600, 'idle_seconds' => 2 * 3600, 'break_seconds' => 3600,
            'first_login_at' => "$day 09:00:00", 'last_logout_at' => "$day 18:00:00",
            'late_minutes' => 12, 'non_productive_seconds' => 0, 'violation_count' => 0,
            'productivity_score' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $e;
    }

    private function row(): array
    {
        $r = $this->withToken($this->token())
            ->getJson('/api/reports/productivity?from=' . self::DAY . '&to=' . self::DAY)
            ->assertOk();

        $rows = $r->json('data');
        $this->assertCount(1, $rows, 'expected exactly one row for the known day');

        return $rows[0];
    }

    /** The headline promise: signed-in span == the buckets it is made of, to the second. */
    public function test_the_day_reconciles_to_the_second(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 10:00:00'));   // the day is history, not live
        $this->buildKnownDay();

        $row = $this->row();

        // Actual Present is exactly sign-out − sign-in: 09:00 → 18:00.
        $this->assertSame(9 * 3600, $row['present_seconds']);
        $this->assertSame('09:00', $row['first_in']);
        $this->assertSame('18:00', $row['last_out']);

        // ...and it is fully accounted for: 6h active + 2h idle + 1h break = 9h. Nothing missing.
        $this->assertSame(6 * 3600, $row['work_seconds']);
        $this->assertSame(2 * 3600, $row['idle_seconds']);
        $this->assertSame(3600, $row['break_seconds']);
        $this->assertSame(0, $row['unaccounted_seconds'], 'not a single second may go unexplained');
        $this->assertSame(
            $row['present_seconds'],
            $row['work_seconds'] + $row['idle_seconds'] + $row['break_seconds']
        );

        // A balanced day carries no Data Issue text.
        $this->assertNull($row['data_issue']);
        $this->assertNull($row['data_issue_text']);
    }

    /**
     * THE regression test. Before the fix these two printed 0 on every historical row while the
     * break SECONDS beside them were correct — the contradiction in the client's sheet.
     */
    public function test_break_count_and_late_minutes_survive_the_summary_date_cast(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 10:00:00'));
        $this->buildKnownDay();

        $row = $this->row();

        $this->assertSame(2, $row['break_count'], 'break COUNT missed its lookup key before the fix');
        $this->assertSame(3600, $row['break_seconds'], 'break SECONDS were always right — hence the contradiction');
        $this->assertSame(12, $row['late_minutes']);
        $this->assertSame(self::DAY, $row['work_date'], 'work_date must be a plain Y-m-d, never carry a time');
    }

    /** Unaccounted time must be reported, not silently folded into Productive %. */
    public function test_a_gap_in_tracking_is_reported_not_hidden(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 10:00:00'));
        $e = $this->buildKnownDay();

        // Agent stopped for 90 minutes: idle drops, the signed-in span does not.
        \Illuminate\Support\Facades\DB::table('employee_daily_summaries')->where('employee_id', $e->id)
            ->update(['idle_seconds' => 1800, 'present_seconds' => 6 * 3600 + 1800]);
        EmployeeActivityEvent::where('employee_id', $e->id)->where('event_type', 'IDLE')
            ->update(['duration_seconds' => 1800]);

        $row = $this->row();

        $this->assertSame(9 * 3600, $row['present_seconds'], 'the signed-in span is unchanged');
        $this->assertSame(90 * 60, $row['unaccounted_seconds']);
        $this->assertSame('UNACCOUNTED_TIME', $row['data_issue']);
        $this->assertStringContainsString('not recorded as working, idle or break', $row['data_issue_text']);
    }

    /**
     * AI0050's row: a sign-out IS recorded, but tracked activity runs past it. The old code
     * labelled this "No sign-out recorded", contradicting the sign-out printed beside it.
     */
    public function test_activity_after_sign_out_says_so_instead_of_claiming_no_sign_out(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 10:00:00'));
        $e = $this->buildKnownDay();

        // Sign-out at 16:00, but the agent kept tracking the full 9h of buckets.
        \Illuminate\Support\Facades\DB::table('employee_daily_summaries')->where('employee_id', $e->id)
            ->update(['last_logout_at' => self::DAY . ' 16:00:00']);

        $row = $this->row();

        $this->assertSame('ACTIVITY_AFTER_SIGN_OUT', $row['data_issue']);
        $this->assertStringContainsString('exceeds the sign-in→sign-out span', $row['data_issue_text']);
        $this->assertStringNotContainsString('No sign-out recorded', $row['data_issue_text']);
        $this->assertSame('16:00', $row['last_out'], 'the sign-out is still shown');
    }

    /**
     * AI0040's row: no sign-out at all and an idle tail running past the shift. Must be bounded
     * at shift end (18:00) rather than crediting the whole tracked span.
     */
    public function test_missing_sign_out_is_capped_at_shift_end(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 10:00:00'));
        $e = $this->buildKnownDay();

        // Signed in 12:00, never signed out, agent logged idle until well past midnight.
        \Illuminate\Support\Facades\DB::table('employee_daily_summaries')->where('employee_id', $e->id)->update([
            'last_logout_at' => null, 'first_login_at' => self::DAY . ' 12:00:00',
            'active_seconds' => 1200, 'idle_seconds' => 10 * 3600 + 21 * 60,
            'present_seconds' => 10 * 3600 + 41 * 60, 'break_seconds' => 0,
        ]);
        EmployeeBreakLog::where('employee_id', $e->id)->delete();

        $row = $this->row();

        // 12:00 → 18:00 shift end = 6h, not the ~11h the tracked tail would have given.
        $this->assertSame(6 * 3600, $row['present_seconds']);
        $this->assertSame('NO_SIGN_OUT_CAPPED', $row['data_issue']);
        $this->assertStringContainsString('capped at shift end', $row['data_issue_text']);
        // Active work is never cut away by the cap.
        $this->assertSame(1200, $row['work_seconds']);
    }

    /** The Excel export must produce a real date cell, never the boolean FALSE. */
    public function test_excel_export_writes_a_real_date(): void
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->markTestSkipped('PhpSpreadsheet not installed');
        }

        $this->travelTo(Carbon::parse('2026-07-08 10:00:00'));
        $this->buildKnownDay();

        $res = $this->withToken($this->token())
            ->get('/api/export/productivity-report?from=' . self::DAY . '&to=' . self::DAY)
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'prod') . '.xlsx';
        file_put_contents($path, $res->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        $date = $sheet->getCell('E2')->getValue();
        $this->assertIsNumeric($date, 'E2 was written as boolean FALSE before the fix');
        $this->assertSame(self::DAY, \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)->format('Y-m-d'));

        $this->assertSame(2, (int) $sheet->getCell('K2')->getValue(), 'Number of Breaks');
        $this->assertSame(12, (int) $sheet->getCell('T2')->getValue(), 'Late Login (mins)');
        $this->assertSame(0, (int) $sheet->getCell('U2')->getValue(), 'Unaccounted mins — the day balances');
        $this->assertSame('Late Login (mins)', $sheet->getCell('T1')->getValue());

        @unlink($path);
    }
}
