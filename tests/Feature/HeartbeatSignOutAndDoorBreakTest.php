<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeLoginSession;
use App\Models\StatusTimeline;
use App\Services\StatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 23-Sep-2026 (Ejaz, production): shift 09:30–18:00 + 120 min auto sign-out, yet at 20:26 the
 * agent was still signed in, and the live board said "Other break since 18:02" while the agent
 * said Active.
 *  1. Auto sign-out depended only on the background scheduler → now also runs on the heartbeat.
 *  2. A door OUT after the shift opened a break → now it is the day closing.
 *  3. Agent ACTIVE never cleared a door break → now it does.
 */
class HeartbeatSignOutAndDoorBreakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Models\Company::withoutGlobalScopes()->whereKey(1)->update(['timezone' => config('app.timezone')]);
    }

    public function test_heartbeat_signs_the_agent_out_after_shift_end_plus_n_without_any_scheduler(): void
    {
        $this->travelTo(Carbon::parse('2026-07-06 10:00:00')); // MON
        $token = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $device = $this->withToken($token)->postJson('/api/agent/register-device', [
            'device_uuid' => 'HB-1', 'computer_name' => 'HB-PC',
        ])->assertCreated()->json('device_token');

        $e = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->first();
        $e->shift->update(['post_shift_auto_logout_minutes' => 120]); // seeded GEN ends 18:00
        $session = EmployeeLoginSession::create([
            'company_id' => $e->company_id, 'employee_id' => $e->id, 'session_type' => 'CLIENT',
            'device_uuid' => 'HB-1', 'login_at' => '2026-07-06 10:00:00',
        ]);

        // 19:59 — inside the grace: heartbeat OK, session open.
        $this->travelTo(Carbon::parse('2026-07-06 19:59:00'));
        $this->withToken($device)->postJson('/api/agent/heartbeat', ['device_uuid' => 'HB-1'])->assertOk();
        $this->assertNull($session->fresh()->logout_at);

        // 20:01 — past 18:00 + 120: the heartbeat itself closes the session and 401s the agent.
        $this->travelTo(Carbon::parse('2026-07-06 20:01:00'));
        $this->app['auth']->forgetGuards();
        \Illuminate\Support\Facades\Cache::flush(); // the once-a-minute throttle
        $this->withToken($device)->postJson('/api/agent/heartbeat', ['device_uuid' => 'HB-1'])
            ->assertStatus(401)->assertJsonPath('error.code', 'SESSION_ENDED');
        $this->assertSame('2026-07-06 20:00:00', $session->fresh()->logout_at->toDateTimeString());
        $this->assertSame('POST_SHIFT_AUTO', $session->fresh()->logout_reason);
    }

    public function test_agent_activity_ends_a_door_break_but_not_a_manual_one(): void
    {
        $this->travelTo(Carbon::parse('2026-07-06 18:02:00'));
        $e = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $status = app(StatusService::class);

        $status->transition($e, 'ACTIVE', now()->subHour());
        $status->transition($e, 'OTHER_BREAK', now(), ['source' => 'BIOMETRIC']);
        EmployeeBreakLog::create(['company_id' => 1, 'employee_id' => $e->id, 'break_type' => 'CUSTOM',
            'source' => 'BIOMETRIC', 'start_at' => now(), 'approval_status' => 'NOT_REQUIRED']);

        $status->transition($e, 'ACTIVE', now()->addMinutes(5));
        $this->assertSame('ACTIVE', $status->currentState($e));
        $this->assertSame(300, (int) EmployeeBreakLog::where('employee_id', $e->id)->first()->duration_seconds);

        // A manual break is still protected from ambient activity.
        $status->transition($e, 'TEA_BREAK', now()->addMinutes(10), ['manual' => true]);
        $status->transition($e, 'ACTIVE', now()->addMinutes(11));
        $this->assertSame('TEA_BREAK', $status->currentState($e));
        $this->assertSame(1, StatusTimeline::where('employee_id', $e->id)->whereNull('ended_at')->count());
    }
}
