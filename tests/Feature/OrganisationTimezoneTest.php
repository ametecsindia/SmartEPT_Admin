<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeAttendanceLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 23-Sep-2026 (Ejaz): the Organisation-tab timezone is the clock — never UTC / server time —
 * including on a server that hosts more than one company (where the boot-time override is off).
 * App runs UTC here, the company is Asia/Kolkata, and a second company exists.
 */
class OrganisationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => 'Asia/Kolkata']);
        $second = Company::withoutGlobalScopes()->find(1)->replicate();
        $second->forceFill(['name' => 'Second Co', 'code' => 'SECOND', 'timezone' => 'UTC'] + (array_key_exists('slug', $second->getAttributes()) ? ['slug' => 'second-co'] : []))->save();
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_api_requests_run_on_the_companys_clock_on_a_multi_company_server(): void
    {
        $this->travelTo(Carbon::parse('2026-07-06 06:00:00', 'UTC')); // 11:30 IST
        $token = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $device = $this->withToken($token)->postJson('/api/agent/register-device', [
            'device_uuid' => 'TZ-1', 'computer_name' => 'TZ-PC',
        ])->assertCreated()->json('device_token');

        $time = $this->withToken($device)->postJson('/api/agent/heartbeat', ['device_uuid' => 'TZ-1'])
            ->assertOk()->json('server_time');
        $this->assertStringStartsWith('2026-07-06T11:30', $time);
        $this->assertStringEndsWith('+05:30', $time);
        $this->assertSame('UTC', config('app.timezone'), 'restored after the request');
    }

    public function test_nightly_attendance_runs_at_0015_on_the_companys_clock(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'mark-attendance');
        $this->assertNotNull($event);

        // 12:00 UTC = 17:30 IST — not the company's midnight: nothing is marked.
        $this->travelTo(Carbon::parse('2026-07-07 12:00:00', 'UTC'));
        $event->run(app());
        $this->assertSame(0, EmployeeAttendanceLog::withoutGlobalScopes()->where('company_id', 1)->whereDate('work_date', '2026-07-06')->count());

        // 18:45 UTC = 00:15 IST on WED 8-Jul: company 1 completes ITS yesterday, TUE 7-Jul.
        $this->travelTo(Carbon::parse('2026-07-07 18:45:00', 'UTC'));
        $event->run(app());
        $this->assertGreaterThan(0, EmployeeAttendanceLog::withoutGlobalScopes()->where('company_id', 1)
            ->whereDate('work_date', '2026-07-07')->where('status', 'ABSENT')->count());
    }
}
