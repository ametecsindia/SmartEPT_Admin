<?php

namespace Tests\Feature;

use App\Support\SchedulerKeepAlive;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The keep-alive must repair a silent server and stay out of the way of a healthy one
 * (2-Sep-2026). The spawn itself is not exercised — a test that launches a real
 * `schedule:work` would outlive the suite.
 */
class SchedulerKeepAliveTest extends TestCase
{
    public function test_a_server_with_no_heartbeat_is_silent(): void
    {
        Cache::forget('smartept:scheduler_heartbeat');

        $this->assertTrue(SchedulerKeepAlive::schedulerIsSilent());
    }

    public function test_a_stale_heartbeat_is_silent(): void
    {
        Cache::put('smartept:scheduler_heartbeat', now()->subMinutes(40)->toDateTimeString(), 3600);

        $this->assertTrue(SchedulerKeepAlive::schedulerIsSilent());
    }

    public function test_a_fresh_heartbeat_is_left_alone(): void
    {
        Cache::put('smartept:scheduler_heartbeat', now()->toDateTimeString(), 3600);

        $this->assertFalse(SchedulerKeepAlive::schedulerIsSilent(),
            'A server with a working cron must never have a second scheduler spawned at it.');
    }

    /** Console is the one place this must not fire: schedule:work would re-enter it. */
    public function test_it_never_spawns_from_the_console(): void
    {
        Cache::forget('smartept:scheduler_heartbeat');

        $this->assertFalse(SchedulerKeepAlive::shouldSpawn());
    }

    /** A page load must survive a broken cache store (fresh install, no cache table yet). */
    public function test_ensure_never_throws(): void
    {
        Cache::forget('smartept:scheduler_heartbeat');

        SchedulerKeepAlive::ensure();

        $this->assertTrue(true);
    }
    /** 22-Sep-2026: no cron at all → an inline run actually executes the schedule. */
    public function test_inline_run_executes_the_schedule_when_no_cron_beats(): void
    {
        Cache::forget('smartept:scheduler_heartbeat');
        Cache::forget('smartept:scheduler_inline_at');
        Cache::forget('smartept:scheduler_inline_lock');

        SchedulerKeepAlive::runInline();

        $this->assertNotNull(Cache::get('smartept:scheduler_heartbeat'), 'schedule:run never ran');
    }

    /** A real cron is beating → inline stays out of the way. */
    public function test_inline_run_skips_when_a_real_cron_beats(): void
    {
        Cache::put('smartept:scheduler_heartbeat', now()->toDateTimeString(), 3600);
        Cache::forget('smartept:scheduler_inline_at');
        Cache::forget('smartept:scheduler_inline_lock');

        SchedulerKeepAlive::runInline();

        $this->assertNull(Cache::get('smartept:scheduler_inline_at'));
    }
}
