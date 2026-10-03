<?php

namespace Tests\Feature;

use App\Models\BiometricLog;
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
 * 29-Sep-2026 (Ejaz): the "+" detail row (door INs/OUTs, Idle spells + return, Away + back at
 * desk) and the report's Away corrections: Gate → PC after each return, no Idle double count.
 */
class ProductivityDayDetailTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-07';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
    }

    private function day(): array
    {
        $d = self::DAY;
        $this->travelTo(Carbon::parse("$d 13:00:00"));
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $base = ['company_id' => 1, 'employee_id' => $e->id];

        EmployeeAttendanceLog::create($base + ['work_date' => $d, 'source' => 'CLIENT', 'status' => 'PRESENT', 'check_in_at' => "$d 09:00:00"]);
        EmployeeLoginSession::create($base + ['device_uuid' => 'DEV-T', 'session_type' => 'CLIENT', 'login_at' => "$d 09:00:00"]);
        foreach ([['IN', '08:55:00'], ['OUT', '11:00:00'], ['IN', '11:20:00']] as [$t, $at]) {
            BiometricLog::withoutGlobalScopes()->create($base + ['punch_type' => $t, 'punched_at' => "$d $at", 'biometric_employee_id' => 'B1']);
        }
        // Door OUT 11:00 → IN 11:20 with no Break: GateService's unannounced break.
        EmployeeBreakLog::create($base + ['break_type' => 'CUSTOM', 'source' => 'BIOMETRIC', 'start_at' => "$d 11:00:00",
            'end_at' => "$d 11:20:00", 'duration_seconds' => 1200]);
        $ev = fn ($type, $s, $x) => EmployeeActivityEvent::create($base + ['event_type' => $type, 'started_at' => "$d $s",
            'ended_at' => "$d $x", 'duration_seconds' => Carbon::parse("$d $x")->diffInSeconds(Carbon::parse("$d $s"), true)]);
        $ev('ACTIVE', '09:00:00', '10:00:00');
        $ev('IDLE', '10:00:00', '10:01:00');   // idle spell 1 (two flushed stretches)
        $ev('IDLE', '10:01:00', '10:05:00');
        $ev('ACTIVE', '10:05:00', '10:58:00');
        $ev('IDLE', '10:58:00', '11:23:00');   // spell 2: walked out + back; 11:20→11:23 is the walk in
        $ev('ACTIVE', '11:23:00', '13:00:00');

        return [$e, $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])->json('token')];
    }

    public function test_detail_lists_every_event_with_times(): void
    {
        [$e, $token] = $this->day();
        $x = $this->withToken($token)->getJson('/api/reports/productivity/detail?employee_id=' . $e->id . '&date=' . self::DAY)
            ->assertOk()->json('data');

        $this->assertSame([['n' => 1, 'in' => '08:55:00', 'out' => '11:00:00'], ['n' => 2, 'in' => '11:20:00', 'out' => null]], $x['door']);
        $this->assertCount(2, $x['idle']);
        $this->assertSame(['10:00:00', '10:05:00', '10:05:00', 300], [$x['idle'][0]['from'], $x['idle'][0]['to'],
            $x['idle'][0]['returned_to_active'], $x['idle'][0]['counted_seconds']]);
        // 10:58→11:23 idle: 11:00–11:23 is inside Away (11:00–11:20) + walk in (11:20–11:23).
        $this->assertSame([1500, 1380, 120], [$x['idle'][1]['seconds'], $x['idle'][1]['in_away_seconds'], $x['idle'][1]['counted_seconds']]);
        // Away = out of the door 20m + walk back 3m (Ejaz: the walk back in is Away too).
        $this->assertSame(['11:00:00', '11:20:00', '11:23:00', 1200, 180, 1380], [$x['away'][0]['out'], $x['away'][0]['in'],
            $x['away'][0]['back_at_desk'], $x['away'][0]['door_seconds'], $x['away'][0]['gate_to_pc_seconds'], $x['away'][0]['away_seconds']]);
        $this->assertSame(300, $x['totals']['first_gate_to_pc_seconds']);     // 08:55 door → 09:00 sign-in
        $this->assertSame(300, $x['totals']['gate_to_pc_seconds']);
        $this->assertSame(1380, $x['totals']['away_seconds']);
        $this->assertSame(420, $x['totals']['idle_seconds']);
    }

    public function test_report_row_counts_the_walk_back_as_away_and_stops_double_counting_idle(): void
    {
        [$e, $token] = $this->day();
        $row = $this->withToken($token)->getJson('/api/reports/productivity?from=' . self::DAY . '&to=' . self::DAY)
            ->assertOk()->json('data.0');

        $this->assertSame($e->id, $row['employee_id']);
        $this->assertSame(420, $row['idle_seconds'], 'idle inside Away / walk-in is not counted again');
        $this->assertSame(300, $row['gate_to_pc_seconds'], 'first walk-in only');
        $this->assertSame(1380, $row['away_seconds'], 'out of the door + walk back to the desk');
        $this->assertSame(420 + 1380, $row['non_productive_seconds']);
        $this->assertLessThanOrEqual(60, abs($row['unaccounted_seconds']));
    }
}
