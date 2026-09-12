<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * smartept:why-no-signout must name the ONE broken link, per employee, for the four
 * shapes the "still signed in" report has arrived in (2-Sep-2026).
 */
class WhyNoSignOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
    }

    private function employee(): Employee
    {
        return Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
    }

    private function openSession(Employee $e, string $loginAt): EmployeeLoginSession
    {
        return EmployeeLoginSession::withoutGlobalScopes()->create([
            'company_id' => $e->company_id, 'employee_id' => $e->id,
            'login_at' => Carbon::parse($loginAt), 'logout_at' => null,
        ]);
    }

    /** A fresh heartbeat, so link 1 is green and the later links are reachable. */
    private function schedulerRunning(): void
    {
        Cache::put('smartept:scheduler_heartbeat', now()->toDateTimeString(), now()->addMinutes(30));
    }

    public function test_a_missing_scheduler_is_reported_as_the_blocking_link(): void
    {
        Cache::forget('smartept:scheduler_heartbeat');

        $this->artisan('smartept:why-no-signout')
            ->expectsOutputToContain('BACKGROUND SCHEDULER — NOT RUNNING')
            ->expectsOutputToContain('schedule:run')
            ->assertSuccessful();
    }

    public function test_a_stale_heartbeat_is_reported_as_stopped(): void
    {
        Cache::put('smartept:scheduler_heartbeat', now()->subMinutes(40)->toDateTimeString(), now()->addMinutes(60));

        $this->artisan('smartept:why-no-signout')
            ->expectsOutputToContain('BACKGROUND SCHEDULER — STOPPED')
            ->assertSuccessful();
    }

    public function test_blank_minutes_are_named_as_not_configured(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 20:00:00'));
        $this->schedulerRunning();

        $e = $this->employee();
        $e->shift->update(['start_time' => '09:00:00', 'end_time' => '18:00:00',
            'crosses_midnight' => false, 'post_shift_auto_logout_minutes' => null]);
        $this->openSession($e, '2026-07-07 09:05:00');

        $this->artisan('smartept:why-no-signout')
            ->expectsOutputToContain('NOT CONFIGURED')
            ->assertSuccessful();
    }

    public function test_a_configured_overdue_session_is_reported_as_due(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 20:00:00'));
        $this->schedulerRunning();

        $e = $this->employee();
        $e->shift->update(['start_time' => '09:00:00', 'end_time' => '18:00:00',
            'crosses_midnight' => false, 'post_shift_auto_logout_minutes' => 30]);
        $this->openSession($e, '2026-07-07 09:05:00');

        // 18:00 + 30m = 18:30, and it is 20:00 — overdue, so the close itself is the fault.
        $this->artisan('smartept:why-no-signout')
            ->expectsOutputToContain('DUE 2026-07-07 18:30')
            ->assertSuccessful();
    }

    public function test_a_session_still_inside_its_shift_is_not_flagged(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 14:00:00'));
        $this->schedulerRunning();

        $e = $this->employee();
        $e->shift->update(['start_time' => '09:00:00', 'end_time' => '18:00:00',
            'crosses_midnight' => false, 'post_shift_auto_logout_minutes' => 30]);
        $this->openSession($e, '2026-07-07 09:05:00');

        $this->artisan('smartept:why-no-signout')
            ->expectsOutputToContain('No open session is overdue')
            ->assertSuccessful();
    }

    public function test_a_session_past_the_lookback_is_named_as_the_nightly_jobs(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 20:00:00'));
        $this->schedulerRunning();

        $e = $this->employee();
        $e->shift->update(['start_time' => '09:00:00', 'end_time' => '18:00:00',
            'crosses_midnight' => false, 'post_shift_auto_logout_minutes' => 30]);
        $this->openSession($e, '2026-07-07 09:05:00');

        $this->artisan('smartept:why-no-signout')
            ->expectsOutputToContain('NIGHTLY job owns it')
            ->assertSuccessful();
    }
}
