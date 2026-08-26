<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 26-Aug-2026 (Ejaz): "the dashboard shows the agent signed out and not active … active agents
 * shows 0, but still the violations are captured from the agent and saved in the violations
 * tab. This is a serious bug."
 *
 * The sign-out design leaned entirely on deleting the device's Sanctum token to make the agent
 * 401 and stop. Any close that missed the token left an agent holding a live credential —
 * still tracking, still uploading — while every screen said the employee was signed out.
 * Two paths did exactly that, and both are covered here along with the guard that makes the
 * whole class of bug impossible.
 */
class ClosedSessionRejectsAgentDataTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-07';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
        $this->travelTo(Carbon::parse(self::DAY . ' 10:00:00'));
    }

    private function employee(): Employee
    {
        return Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
    }

    /** Sign the agent in for real, so the token is minted exactly as production mints it. */
    private function signInAgent(Employee $e): string
    {
        $userToken = $this->postJson('/api/auth/login', [
            'email' => $e->user->email, 'password' => 'password',
        ])->assertOk()->json('token');

        $deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', [
            'device_uuid' => 'uuid-live-session', 'computer_name' => 'TEST-PC',
        ])->assertSuccessful()->json('device_token');

        // Ingestion sits behind the consent wall, exactly as it does in production.
        $this->withToken($deviceToken)->postJson('/api/agent/consent', [
            'device_uuid' => 'uuid-live-session', 'acknowledged' => true,
            'consent_text_hash' => str_repeat('a', 64),
        ])->assertSuccessful();

        return $deviceToken;
    }

    /** register-device does not open a login session; attendance-event does. */
    private function openLoginSession(Employee $e, string $loginAt): EmployeeLoginSession
    {
        return EmployeeLoginSession::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-live-session',
            'session_type' => 'CLIENT', 'login_at' => $loginAt,
        ]);
    }

    private function postViolation(string $deviceToken)
    {
        return $this->withToken($deviceToken)->postJson('/api/agent/compliance-event', [
            'device_uuid' => 'uuid-live-session',
            'event_type' => 'BLOCKED_APP',
            'event_category' => 'APP',
            'severity' => 'HIGH',
            'description' => 'steam.exe',
            'started_at' => self::DAY . ' 10:05:00',
        ]);
    }

    /** Baseline: while the session is live, violations are of course accepted. */
    public function test_a_live_session_still_records_violations(): void
    {
        $token = $this->signInAgent($this->employee());

        $this->postViolation($token)->assertSuccessful();
        $this->assertSame(1, EmployeeComplianceEvent::withoutGlobalScopes()->count());
    }

    /**
     * THE bug. A closed session must not accept a single further violation, even from an
     * agent that somehow still holds a valid token.
     */
    public function test_a_closed_session_records_nothing_even_with_a_valid_token(): void
    {
        $e = $this->employee();
        $token = $this->signInAgent($e);

        // Close the session the way a console force-logout / stale sweep leaves the row,
        // WITHOUT touching the token — the exact state that produced the bug.
        EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'uuid-live-session')
            ->update(['session_status' => 'LOGGED_OUT', 'current_status' => 'OFFLINE']);

        $this->postViolation($token)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'SESSION_ENDED');

        $this->assertSame(0, EmployeeComplianceEvent::withoutGlobalScopes()->count(),
            'a signed-out agent must not be able to write violations');
    }

    /**
     * The heartbeat is how the agent LEARNS it is signed out (main.js handleSessionRevoked()
     * fires on a 401). It must not keep answering 200 to a closed session.
     */
    public function test_the_heartbeat_tells_a_closed_session_to_sign_out(): void
    {
        $token = $this->signInAgent($this->employee());

        $this->withToken($token)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'uuid-live-session', 'status' => 'ACTIVE',
        ])->assertSuccessful();

        EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'uuid-live-session')
            ->update(['session_status' => 'FORCE_LOGOUT']);

        $this->withToken($token)->postJson('/api/agent/heartbeat', [
            'device_uuid' => 'uuid-live-session', 'status' => 'ACTIVE',
        ])->assertStatus(401)->assertJsonPath('error.code', 'SESSION_ENDED');
    }

    /** An unbound device is signed out too — it must not keep uploading either. */
    public function test_an_unbound_device_records_nothing(): void
    {
        $token = $this->signInAgent($this->employee());

        EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'uuid-live-session')
            ->update(['unbound_at' => now()]);

        $this->postViolation($token)->assertStatus(401);
        $this->assertSame(0, EmployeeComplianceEvent::withoutGlobalScopes()->count());
    }

    /**
     * The post-shift close skipped any device whose session_status had already moved off
     * ACTIVE, so its token was never revoked. Revocation must not depend on the row's state.
     */
    public function test_post_shift_close_revokes_the_token_even_if_the_row_already_moved(): void
    {
        $e = $this->employee();
        $this->signInAgent($e);
        $e->shift->update(['start_time' => '01:00:00', 'end_time' => '09:00:00',
            'crosses_midnight' => false, 'post_shift_auto_logout_minutes' => 1]);

        // Something already flipped the row — a console force-logout, an earlier sweep.
        EmployeeDevice::withoutGlobalScopes()->where('device_uuid', 'uuid-live-session')
            ->update(['session_status' => 'FORCE_LOGOUT']);

        $this->openLoginSession($e, self::DAY . ' 08:00:00');

        $this->artisan('smartept:auto-logout')->assertExitCode(0);

        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::where('name', 'device:uuid-live-session')->count(),
            'the token must be revoked regardless of what session_status already said');
    }

    /** The nightly stale-session close flipped the row but never revoked the token. */
    public function test_the_nightly_close_also_revokes_the_token(): void
    {
        $e = $this->employee();
        $this->signInAgent($e);

        $this->openLoginSession($e, '2026-07-06 09:00:00');

        $this->artisan('smartept:mark-attendance', ['--date' => '2026-07-06'])->assertExitCode(0);

        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::where('name', 'device:uuid-live-session')->count(),
            'the nightly close left the agent holding a live token');
    }
}
