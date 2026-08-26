<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Shift-bounded agent sign-in (Ejaz, 26-Aug-2026): "the employee should not be able to sign in
 * to the Agent app if it is not within the employee's shift … it should restrict only for the
 * Agent app, not the admin console".
 *
 * Enforced at POST /api/agent/register-device — the agent's only sign-in path, and one the
 * console never calls. Gating /api/auth/login instead would have locked admins out of the
 * console after 18:00, which is the whole reason the check lives here.
 *
 * Seeded GEN shift: 09:00–18:00, MON–FRI. 2026-07-07 is a Tuesday.
 */
class ShiftBoundedSignInTest extends TestCase
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

    /** Sign in as the employee's own user, exactly as the agent does. */
    private function agentToken(Employee $e): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $e->user->email, 'password' => 'password',
        ])->assertOk()->json('token');
    }

    private function attemptRegister(Employee $e, string $uuid = 'uuid-shift-test')
    {
        return $this->withToken($this->agentToken($e))->postJson('/api/agent/register-device', [
            'device_uuid' => $uuid, 'computer_name' => 'TEST-PC',
        ]);
    }

    private function restrict(Employee $e): void
    {
        $e->shift->update(['start_time' => '09:00:00', 'end_time' => '18:00:00',
            'crosses_midnight' => false, 'post_shift_auto_logout_minutes' => null,
            'restrict_login_to_shift' => true]);
    }

    public function test_sign_in_inside_the_shift_is_allowed(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 09:30:00'));
        $e = $this->employee();
        $this->restrict($e);

        $this->attemptRegister($e)->assertSuccessful()->assertJsonStructure(['device_token']);
    }

    public function test_sign_in_before_the_shift_starts_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 08:59:00'));
        $e = $this->employee();
        $this->restrict($e);

        $this->attemptRegister($e)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'OUTSIDE_SHIFT')
            ->assertJsonPath('error.message', fn ($m) => str_contains($m, '09:00–18:00'));

        $this->assertDatabaseMissing('employee_devices', ['device_uuid' => 'uuid-shift-test']);
    }

    public function test_sign_in_after_the_shift_ends_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 18:01:00'));
        $e = $this->employee();
        $this->restrict($e);

        $this->attemptRegister($e)->assertStatus(403)->assertJsonPath('error.code', 'OUTSIDE_SHIFT');
    }

    /** The exact boundaries are inside the window, not outside it. */
    public function test_the_boundaries_themselves_are_allowed(): void
    {
        $e = $this->employee();
        $this->restrict($e);

        $this->travelTo(Carbon::parse('2026-07-07 09:00:00'));
        $this->attemptRegister($e, 'uuid-open')->assertSuccessful();

        $this->travelTo(Carbon::parse('2026-07-07 18:00:00'));
        $this->attemptRegister($e, 'uuid-open')->assertSuccessful();
    }

    /**
     * The tail follows the auto sign-out grace, so the two settings can never disagree —
     * you may not sign in at an instant the server would immediately sign you back out.
     */
    public function test_the_window_extends_by_the_auto_sign_out_grace(): void
    {
        $e = $this->employee();
        $this->restrict($e);
        $e->shift->update(['post_shift_auto_logout_minutes' => 30]);

        $this->travelTo(Carbon::parse('2026-07-07 18:25:00'));
        $this->attemptRegister($e, 'uuid-tail')->assertSuccessful();

        $this->travelTo(Carbon::parse('2026-07-07 18:31:00'));
        $this->attemptRegister($e, 'uuid-tail')->assertStatus(403);
    }

    /** A 22:00–06:00 employee at 02:00 is inside YESTERDAY's window, not outside today's. */
    public function test_a_night_shift_is_judged_on_the_window_it_is_inside(): void
    {
        $e = $this->employee();
        $e->shift->update(['start_time' => '22:00:00', 'end_time' => '06:00:00',
            'crosses_midnight' => true, 'restrict_login_to_shift' => true]);

        $this->travelTo(Carbon::parse('2026-07-08 02:00:00'));
        $this->attemptRegister($e, 'uuid-night')->assertSuccessful();

        $this->travelTo(Carbon::parse('2026-07-08 12:00:00'));   // midday — nowhere near the shift
        $this->attemptRegister($e, 'uuid-night')->assertStatus(403);
    }

    /** Off by default: an upgrade must not start refusing anybody. */
    public function test_the_restriction_is_off_unless_the_shift_opts_in(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 03:00:00'));   // nowhere near 09:00–18:00
        $e = $this->employee();
        $e->shift->update(['restrict_login_to_shift' => false]);

        $this->attemptRegister($e)->assertSuccessful();
    }

    /** Half-configured data must never lock somebody out of their own PC. */
    public function test_an_employee_with_no_shift_is_never_blocked(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 03:00:00'));
        $e = $this->employee();
        $e->update(['shift_id' => null]);

        $this->attemptRegister($e)->assertSuccessful();
    }

    /**
     * THE constraint Ejaz added: the admin console must keep working out of hours. It signs in
     * through /api/auth/login and never touches register-device, so the restriction cannot
     * reach it — this pins that so a later refactor cannot quietly move the gate.
     */
    public function test_the_admin_console_still_signs_in_outside_shift_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 23:30:00'));
        $e = $this->employee();
        $this->restrict($e);

        // The employee's own account, well outside the shift — console login still succeeds.
        $this->postJson('/api/auth/login', ['email' => $e->user->email, 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['token']);

        // And the administrator's account, which has no shift at all.
        $this->postJson('/api/auth/login', ['email' => 'admin@ametecs.io', 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    /** A device already registered stays usable; only NEW sign-ins are gated. */
    public function test_an_existing_session_is_not_torn_down_by_the_restriction(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 18:30:00'));
        $e = $this->employee();
        $this->restrict($e);

        $device = EmployeeDevice::create([
            'company_id' => 1, 'employee_id' => $e->id, 'device_uuid' => 'uuid-live',
            'session_status' => 'ACTIVE', 'current_status' => 'ONLINE',
            'last_heartbeat_at' => '2026-07-07 18:29:00',
        ]);

        // Sign-in is refused...
        $this->attemptRegister($e, 'uuid-new')->assertStatus(403);

        // ...but the running session is untouched. Ending it is the auto sign-out's job,
        // on its own schedule — this check must not double as a second way to kick people.
        $this->assertSame('ACTIVE', $device->refresh()->session_status);
    }
}
