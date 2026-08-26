<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 26-Aug-2026 (Ejaz): "employees who signed in yesterday and did not sign out are still
 * showing as online today".
 *
 * Three separate defects produced that one symptom, and each gets a test here:
 *
 *  1. `smartept:mark-attendance` closed only sessions from BEFORE the day it processes.
 *     Running at 00:15 to complete yesterday, it therefore skipped yesterday's own
 *     forgotten sessions — they survived a further 24 hours.
 *  2. Closing the login session did not stamp `check_out_at` or touch the device row, so
 *     the attendance sheet stayed open-ended and the Devices screen still printed ONLINE.
 *  3. `smartept:auto-logout` returned null for an employee with no shift, so that employee
 *     could never be signed out by the near-real-time path either.
 *
 * Seeded GEN shift is 09:00–18:00, MON–FRI. 2026-07-06 = MON, 2026-07-07 = TUE.
 */
class PostShiftSignOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Every test here except the timezone one is about sign-out BEHAVIOUR, so pin the
        // company's clock to the app's and vary one thing at a time. Pinned to
        // config('app.timezone'), NOT a literal 'UTC': a literal silently re-created the
        // 5h30m skew on any box whose .env is not UTC, which is exactly why this file passed
        // in the sandbox and failed on the Asia/Kolkata dev machine (26-Aug-2026).
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
    }

    private function employee(string $code): Employee
    {
        return Employee::withoutGlobalScopes()->where('employee_code', $code)->firstOrFail();
    }

    private function openSession(Employee $e, string $loginAt): EmployeeLoginSession
    {
        return EmployeeLoginSession::create([
            'company_id' => 1, 'employee_id' => $e->id, 'session_type' => 'CLIENT', 'login_at' => $loginAt,
        ]);
    }

    /** Defect 1 + 2: the nightly run must finish the day it is completing, not the one before. */
    public function test_nightly_run_closes_the_processed_days_forgotten_session(): void
    {
        // 00:15 on the 8th, completing the 7th — exactly when the scheduler fires.
        $this->travelTo(Carbon::parse('2026-07-08 00:15:00'));
        $e = $this->employee('E-1001');   // GEN shift, ends 18:00

        $session = $this->openSession($e, '2026-07-07 09:05:00');
        $attendance = EmployeeAttendanceLog::create([
            'company_id' => 1, 'employee_id' => $e->id, 'work_date' => '2026-07-07',
            'source' => 'CLIENT', 'status' => 'PRESENT', 'check_in_at' => '2026-07-07 09:05:00',
        ]);
        $device = EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-test-1',
            'session_status' => 'ACTIVE', 'current_status' => 'ONLINE',
        ]);

        $this->artisan('smartept:mark-attendance', ['--date' => '2026-07-07'])->assertExitCode(0);

        // The session is closed at the login day's shift end...
        $session->refresh();
        $this->assertSame('2026-07-07 18:00:00', $session->logout_at->toDateTimeString());
        $this->assertSame('AUTO_CLOSED', $session->logout_reason);

        // ...the attendance sheet now HAS a sign-out (this is what the productivity report
        // divides by — without it the row shows "no sign-out recorded" and an inflated day)...
        $attendance->refresh();
        $this->assertSame('2026-07-07 18:00:00', $attendance->check_out_at->toDateTimeString());

        // ...and the Devices screen no longer claims the employee is online.
        $device->refresh();
        $this->assertSame('LOGGED_OUT', $device->session_status);
        $this->assertSame('OFFLINE', $device->current_status);
    }

    /** The guard the old `< $date` filter was really providing: a night shift in progress. */
    public function test_night_shift_still_running_is_not_closed(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 00:15:00'));
        $e = $this->employee('E-1002');
        $e->shift->update(['start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true]);

        // Signed in at 22:10 on the 7th; the shift runs to 06:00 on the 8th, so at 00:15
        // this employee is mid-shift and must be left alone.
        $session = $this->openSession($e, '2026-07-07 22:10:00');

        $this->artisan('smartept:mark-attendance', ['--date' => '2026-07-07'])->assertExitCode(0);

        $session->refresh();
        $this->assertNull($session->logout_at, 'a night shift in progress must not be auto-closed');
    }

    /** Defect 3: no shift assigned used to mean "can never be auto-signed-out". */
    public function test_auto_logout_handles_an_employee_with_no_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 09:00:00'));
        $e = $this->employee('E-1002');
        $e->update(['shift_id' => null]);

        $session = $this->openSession($e, '2026-07-07 10:00:00');

        // Configure the fallback on the company Attendance policy so the feature is on.
        // Note the ASSIGNMENT: an Attendance policy that is created but never assigned to
        // any level resolves to null, and the auto sign-out stays off for everybody. That
        // is one of the two things `--explain` now names out loud.
        $policy = \App\Models\AttendancePolicy::withoutGlobalScopes()->create([
            'company_id' => 1, 'name' => 'Default attendance', 'version' => 1,
            'post_shift_auto_logout_minutes' => 30,
        ]);
        \App\Models\PolicyAssignment::withoutGlobalScopes()->create([
            'company_id' => 1, 'policy_type' => 'ATTENDANCE', 'policy_id' => $policy->id,
            'assignable_type' => 'COMPANY', 'assignable_id' => 1,
        ]);

        $this->artisan('smartept:auto-logout')->assertExitCode(0);

        $session->refresh();
        $this->assertNotNull($session->logout_at, 'a shiftless employee must still be signed out');
        $this->assertSame('POST_SHIFT_AUTO', $session->logout_reason);
        // End of the login day + the configured 30 minutes.
        $this->assertSame('2026-07-08 00:29:59', $session->logout_at->toDateTimeString());
    }

    /**
     * Signing back in after the shift has ended must NOT buy a fresh window — the post-shift
     * cutoff still governs (Ejaz, 26-Aug: "irrespective of the re-login ... it should sign out
     * the agent"). `addDay()` used to hand such a session the whole of the next day.
     */
    public function test_login_after_shift_end_still_signs_out(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 19:35:00'));
        $e = $this->employee('E-1001');                       // GEN shift, 09:00–18:00
        $e->shift->update(['post_shift_auto_logout_minutes' => 1]);

        $session = $this->openSession($e, '2026-07-07 19:30:00');

        $this->artisan('smartept:auto-logout')->assertExitCode(0);

        $session->refresh();
        $this->assertNotNull($session->logout_at, 'a session started after shift end must still close');
        // Cutoff (18:01) precedes the login, so the login instant is stamped rather than a
        // logout that runs backwards.
        $this->assertSame('2026-07-07 19:30:00', $session->logout_at->toDateTimeString());
        $this->assertSame(0, (int) $session->duration_seconds);
    }

    /**
     * THE 26-Aug production bug. `.env` ships APP_TIMEZONE=UTC, but the agent stores local
     * wall-clock times and shifts.end_time is a local clock face. Comparing them against a UTC
     * now() is a 5h30m error for an India tenant: a shift that ended two minutes ago reported
     * "not due yet", and only became due 5h30m late.
     */
    public function test_due_check_uses_the_companys_wall_clock_not_utc(): void
    {
        // BOTH clocks pinned explicitly — this test is about them disagreeing, so it must not
        // inherit whatever the running machine's .env happens to say.
        config(['app.timezone' => 'UTC']);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => 'Asia/Kolkata']);

        // 12:16 in Kolkata is 06:46 UTC. The shift ended at 12:13 local + 1 minute = 12:14
        // local, so it IS due — but a UTC "now" of 06:46 reads as five and a half hours early.
        $this->travelTo(Carbon::parse('2026-07-07 06:46:00', 'UTC'));

        $e = $this->employee('E-1001');
        $e->shift->update(['start_time' => '09:00:00', 'end_time' => '12:13:00',
            'post_shift_auto_logout_minutes' => 1]);

        $session = $this->openSession($e, '2026-07-07 12:10:00');   // stored local, as the agent writes it

        $this->artisan('smartept:auto-logout')->assertExitCode(0);

        $session->refresh();
        $this->assertNotNull($session->logout_at, 'shift ended at 12:14 local — must be due');
        $this->assertSame('2026-07-07 12:14:00', $session->logout_at->toDateTimeString());
    }

    /**
     * The agent only signs itself out when its next heartbeat 401s, so the auto sign-out is
     * worth nothing unless the token is really gone. Revocation used to walk
     * employee → user → tokens, which silently revoked NOTHING when the token belonged to a
     * different account than employees.user_id — the server closed the session while the agent
     * kept its live token, kept tracking, and kept showing "Signed in".
     */
    public function test_auto_sign_out_revokes_the_agent_token_whoever_holds_it(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 09:00:00'));
        $e = $this->employee('E-1001');
        $e->shift->update(['post_shift_auto_logout_minutes' => 1]);

        // The token lives on an account that is NOT $employee->user — the case that broke.
        $other = \App\Models\User::withoutGlobalScopes()->where('id', '!=', $e->user_id)->firstOrFail();
        $e->update(['user_id' => null]);
        $other->createToken('device:uuid-agent', ['agent']);

        EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-agent',
            'session_status' => 'ACTIVE', 'current_status' => 'ONLINE',
        ]);
        $this->openSession($e, '2026-07-07 09:00:00');

        $this->assertSame(1, \Laravel\Sanctum\PersonalAccessToken::where('name', 'device:uuid-agent')->count());

        $this->artisan('smartept:auto-logout')->assertExitCode(0);

        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::where('name', 'device:uuid-agent')->count(),
            'the agent keeps running until its token is gone');
    }

    /**
     * A PC that is simply switched off must stop reading ONLINE — decided when the list is
     * READ, never by writing OFFLINE into the column on a schedule. A writer fights the
     * heartbeat for the same field and a live agent ends up shown as offline (26-Aug-2026).
     */
    public function test_a_silent_device_reads_offline_without_the_column_being_rewritten(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 09:00:00'));
        $e = $this->employee('E-1001');

        $silent = EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-silent',
            'session_status' => 'ACTIVE', 'current_status' => 'ONLINE',
            'last_heartbeat_at' => '2026-07-08 08:30:00',
        ]);
        $live = EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-live',
            'session_status' => 'ACTIVE', 'current_status' => 'ONLINE',
            'last_heartbeat_at' => '2026-07-08 08:59:30',
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'password',
        ])->assertOk()->json('token');

        $rows = collect($this->withToken($token)->getJson('/api/devices')->assertOk()->json('data'))
            ->keyBy('device_uuid');

        $this->assertSame('OFFLINE', $rows['uuid-silent']['current_status']);
        $this->assertSame('ONLINE', $rows['uuid-live']['current_status']);

        // The stored column is untouched — the heartbeat remains its only writer.
        $this->assertSame('ONLINE', $silent->refresh()->current_status);
        $this->assertSame('ACTIVE', $silent->session_status);
        $this->assertSame('ONLINE', $live->refresh()->current_status);
    }
}
