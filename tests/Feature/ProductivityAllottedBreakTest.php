<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 29-Sep-2026 (Ejaz): Allotted break = the shift's "Break allowed" in full; pro rata only on a
 * late login / early logout. General 09:00–18:00, 65 min.
 */
class ProductivityAllottedBreakTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-07';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
    }

    private function allotted(string $in, ?string $out, string $now): array
    {
        $d = self::DAY;
        $this->travelTo(Carbon::parse("$d $now"));
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $e->shift->update(['start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_minutes_allowed' => 65]);
        $base = ['company_id' => 1, 'employee_id' => $e->id];
        EmployeeAttendanceLog::create($base + ['work_date' => $d, 'source' => 'CLIENT', 'status' => 'PRESENT',
            'check_in_at' => "$d $in", 'check_out_at' => $out ? "$d $out" : null]);
        EmployeeLoginSession::create($base + ['device_uuid' => 'DEV-T', 'session_type' => 'CLIENT', 'login_at' => "$d $in",
            'logout_at' => $out ? "$d $out" : null, 'logout_reason' => $out ? 'USER' : null]);
        $end = $out ?? $now;
        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d $in", 'ended_at' => "$d $end",
            'duration_seconds' => Carbon::parse("$d $end")->diffInSeconds(Carbon::parse("$d $in"), true)]);
        $token = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $row = $this->withToken($token)->getJson('/api/reports/productivity?from=' . $d . '&to=' . $d)->assertOk()->json('data.0');

        return [(int) round($row['allotted_break_seconds'] / 60), $row['allotted_break_basis']];
    }

    public function test_full_shift_gets_the_full_break(): void
    {
        $this->assertSame(65, $this->allotted('09:00:00', '18:00:00', '19:00:00')[0]);
    }

    public function test_early_logout_is_pro_rata(): void
    {
        [$min, $why] = $this->allotted('09:00:00', '14:00:00', '19:00:00');   // 5h of 9h
        $this->assertSame((int) round(65 * 5 / 9), $min);
        $this->assertStringContainsString('pro rata', $why);
        $this->assertStringStartsWith('General Shift:', $why);
    }

    public function test_today_is_not_an_early_logout_while_the_shift_is_still_running(): void
    {
        $this->assertSame(65, $this->allotted('09:00:00', null, '17:29:00')[0], 'on time, shift not over yet');
    }

    public function test_late_login_today_is_pro_rata(): void
    {
        $this->assertSame((int) round(65 * 7.5 / 9), $this->allotted('10:30:00', null, '17:29:00')[0], '10:30 → 18:00 of 9h');
    }

    /** 29-Sep-2026 (Ejaz): the report's Branch / Department / Team / Employee filter narrows the rows. */
    public function test_department_filter_narrows_the_report(): void
    {
        $this->allotted('09:00:00', '18:00:00', '19:00:00');
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $token = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $q = '/api/reports/productivity?from=' . self::DAY . '&to=' . self::DAY;
        $this->assertCount(1, $this->withToken($token)->getJson($q . '&department_id=' . $e->department_id)->json('data'));
        $other = \App\Models\Department::withoutGlobalScopes()->where('company_id', 1)->where('id', '!=', $e->department_id)->value('id')
            ?? \App\Models\Department::withoutGlobalScopes()->create(['company_id' => 1, 'name' => 'Other dept'])->id;
        $this->assertCount(0, $this->withToken($token)->getJson($q . '&department_id=' . $other)->json('data'));
    }
}
