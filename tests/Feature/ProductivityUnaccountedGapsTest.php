<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 28-Sep-2026 (Ejaz, "tiru" +1h31m): "where did the unaccounted time go?" — the report must
 * name the clock windows that make up Unaccounted, and an OPEN break must count as break.
 */
class ProductivityUnaccountedGapsTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-07';

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
    }

    private function liveDay(bool $openBreak): array
    {
        $d = self::DAY;
        $this->travelTo(Carbon::parse("$d 12:30:00"));
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $base = ['company_id' => 1, 'employee_id' => $e->id];

        EmployeeAttendanceLog::create($base + ['work_date' => $d, 'source' => 'CLIENT', 'status' => 'PRESENT',
            'check_in_at' => "$d 09:00:00"]);
        // Signed out 10:30 → back 11:15 (still open).
        EmployeeLoginSession::create($base + ['device_uuid' => 'DEV-T', 'session_type' => 'CLIENT',
            'login_at' => "$d 09:00:00", 'logout_at' => "$d 10:30:00", 'logout_reason' => 'USER']);
        EmployeeLoginSession::create($base + ['device_uuid' => 'DEV-T', 'session_type' => 'CLIENT',
            'login_at' => "$d 11:15:00"]);

        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d 09:00:00",
            'ended_at' => "$d 10:00:00", 'duration_seconds' => 3600]);
        EmployeeActivityEvent::create($base + ['event_type' => 'IDLE', 'started_at' => "$d 10:00:00",
            'ended_at' => "$d 10:30:00", 'duration_seconds' => 1800]);
        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d 11:15:00",
            'ended_at' => "$d 12:00:00", 'duration_seconds' => 2700]);
        if ($openBreak) {
            EmployeeBreakLog::create($base + ['break_type' => 'TEA', 'start_at' => "$d 12:20:00"]);
        }

        $token = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $rows = $this->withToken($token)->getJson('/api/reports/productivity?from=' . $d . '&to=' . $d)
            ->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->token = $token;

        return $rows[0];
    }

    /** 29-Sep-2026 (Ejaz): signed out → back in = Away; signed in with no agent data = Idle. Nothing Unaccounted. */
    public function test_signed_out_is_away_and_signed_in_without_data_is_idle(): void
    {
        $row = $this->liveDay(openBreak: true);

        $this->assertSame(210 * 60, $row['present_seconds']);            // 09:00 → now 12:30
        $this->assertSame(10 * 60, $row['break_seconds'], 'open break 12:20→now counts as break');
        $this->assertLessThanOrEqual(60, abs($row['unaccounted_seconds']), 'nothing left unaccounted');
        $this->assertSame(45 * 60, $row['away_seconds'], 'signed out 10:30 → signed back in 11:15');
        $this->assertSame(30 * 60 + 20 * 60, $row['idle_seconds'], 'idle 10:00–10:30 + no agent data 12:00–12:20');
        $this->assertSame(95 * 60, $row['non_productive_seconds']);

        $gaps = $row['unaccounted_gaps'];
        $this->assertCount(1, $gaps);
        $this->assertSame(['12:00', '12:20'], [$gaps[0]['from'], $gaps[0]['to']]);
        $this->assertStringContainsString('counted as Idle', $gaps[0]['cause']);
        $this->assertStringContainsString('12:00–12:20', $row['data_issue_text']);

        // The "+" detail shows the same two windows.
        $x = $this->withToken($this->token)->getJson('/api/reports/productivity/detail?employee_id=' . $row['employee_id'] . '&date=' . self::DAY)
            ->assertOk()->json('data');
        $this->assertSame([['Signed out', '10:30:00', '11:15:00', 2700]], array_map(fn ($a) => [$a['how'], $a['out'], $a['in'], $a['away_seconds']], $x['away']));
        $noData = array_values(array_filter($x['idle'], fn ($i) => $i['no_data']));
        $this->assertSame([['12:00:00', '12:20:00', 1200]], array_map(fn ($i) => [$i['from'], $i['to'], $i['counted_seconds']], $noData));
        $this->assertSame(45 * 60, $x['totals']['away_seconds']);
        $this->assertSame(50 * 60, $x['totals']['idle_seconds']);
    }

    public function test_a_live_tail_says_nothing_received_since(): void
    {
        $row = $this->liveDay(openBreak: false);

        $last = end($row['unaccounted_gaps']);
        $this->assertSame(['12:00', '12:30'], [$last['from'], $last['to']]);
        $this->assertStringContainsString('nothing received from the agent since 12:00', $last['cause']);
    }

    /** tiru: signed in all day, locked + walked out 10:30–11:42 with no break → Away, non-productive. */
    public function test_locked_and_out_of_the_door_is_away_not_unaccounted(): void
    {
        $d = self::DAY;
        $this->travelTo(Carbon::parse("$d 12:30:00"));
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $base = ['company_id' => 1, 'employee_id' => $e->id];

        EmployeeAttendanceLog::create($base + ['work_date' => $d, 'source' => 'CLIENT', 'status' => 'PRESENT',
            'check_in_at' => "$d 09:00:00"]);
        EmployeeLoginSession::create($base + ['device_uuid' => 'DEV-T', 'session_type' => 'CLIENT',
            'login_at' => "$d 09:00:00", 'logout_at' => "$d 10:30:00", 'logout_reason' => 'LOCK']);
        EmployeeLoginSession::create($base + ['device_uuid' => 'DEV-T', 'session_type' => 'CLIENT',
            'login_at' => "$d 11:42:00"]);
        foreach ([['OUT', '10:32:00'], ['IN', '11:40:00']] as [$t, $at]) {
            \App\Models\BiometricLog::withoutGlobalScopes()->create($base + ['punch_type' => $t,
                'punched_at' => "$d $at", 'biometric_employee_id' => 'B1']);
        }
        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d 09:00:00",
            'ended_at' => "$d 10:30:00", 'duration_seconds' => 5400]);
        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d 11:42:00",
            'ended_at' => "$d 12:30:00", 'duration_seconds' => 2880]);

        $token = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $row = $this->withToken($token)->getJson('/api/reports/productivity?from=' . $d . '&to=' . $d)
            ->assertOk()->json('data.0');

        $this->assertSame(72 * 60, $row['away_seconds']);
        $this->assertLessThanOrEqual(60, abs($row['unaccounted_seconds']), 'nothing left unexplained');
        $this->assertSame(72 * 60, $row['non_productive_seconds'], 'Away is non-productive (idle 0 + exceed 0 + away)');
        $this->assertNull($row['data_issue'], 'Away has its own column — not a data issue');
    }

    /** 28-Sep-2026: a door punch-out with no Break is Away (Non-Productive), not Break Availed. */
    public function test_door_punch_out_without_break_is_away_not_break(): void
    {
        $d = self::DAY;
        $this->travelTo(Carbon::parse("$d 12:30:00"));
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $base = ['company_id' => 1, 'employee_id' => $e->id];

        EmployeeAttendanceLog::create($base + ['work_date' => $d, 'source' => 'CLIENT', 'status' => 'PRESENT',
            'check_in_at' => "$d 09:00:00"]);
        EmployeeLoginSession::create($base + ['device_uuid' => 'DEV-T', 'session_type' => 'CLIENT', 'login_at' => "$d 09:00:00"]);
        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d 09:00:00",
            'ended_at' => "$d 10:00:00", 'duration_seconds' => 3600]);
        EmployeeActivityEvent::create($base + ['event_type' => 'IDLE', 'started_at' => "$d 10:15:00",
            'ended_at' => "$d 11:00:00", 'duration_seconds' => 2700]);
        EmployeeActivityEvent::create($base + ['event_type' => 'ACTIVE', 'started_at' => "$d 11:45:00",
            'ended_at' => "$d 12:30:00", 'duration_seconds' => 2700]);
        // Declared tea break (started in the agent) …
        EmployeeBreakLog::create($base + ['break_type' => 'TEA', 'source' => 'MANUAL', 'device_uuid' => 'DEV-T',
            'start_at' => "$d 10:00:00", 'end_at' => "$d 10:15:00", 'duration_seconds' => 900]);
        // … and a door punch-out with no Break started (GateService creates it with no device).
        EmployeeBreakLog::create($base + ['break_type' => 'CUSTOM', 'source' => 'BIOMETRIC',
            'start_at' => "$d 11:00:00", 'end_at' => "$d 11:45:00", 'duration_seconds' => 2700]);

        $token = $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token');
        $row = $this->withToken($token)->getJson('/api/reports/productivity?from=' . $d . '&to=' . $d)
            ->assertOk()->json('data.0');

        $this->assertSame(900, $row['break_seconds'], 'only the declared break is Break Availed');
        $this->assertSame(1, $row['break_count']);
        $this->assertSame(2700, $row['away_seconds']);
        $this->assertSame(0, $row['break_exceed_seconds']);
        $this->assertSame(2700 + 2700, $row['non_productive_seconds'], 'Idle 45m + Break Exceed 0 + Away 45m');
        $this->assertLessThanOrEqual(60, abs($row['unaccounted_seconds']));
    }
}
