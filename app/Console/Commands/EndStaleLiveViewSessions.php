<?php

namespace App\Console\Commands;

use App\Models\LiveViewSession;
use Illuminate\Console\Command;

/**
 * LiveView Phase 4 (14-Sep-2026): a session only left `connecting`/`live` via an
 * explicit Stop click (LiveViewController::stop) — if the Agent goes dark mid-session
 * (crash, network loss, PC sleep) the row, and the concurrency slot it holds against
 * the licence's liveview_max_concurrent limit, never clears. This sweep closes it.
 *
 * Reuses config('liveview.device_online_window_seconds') — the same threshold
 * DeviceController::liveviewRequestedFor() already uses to judge "is this device
 * still around" — so a session times out at the same point the Agent itself would
 * already be considered offline, and last_heartbeat_at is bumped by that same
 * heartbeat round-trip (see DeviceController::heartbeat()).
 *
 * Runs every minute (routes/console.php) — cheap headroom under a 90s window.
 */
class EndStaleLiveViewSessions extends Command
{
    protected $signature = 'smartept:end-stale-liveview-sessions';

    protected $description = 'End LiveView sessions whose Agent has stopped heartbeating';

    public function handle(): int
    {
        $cutoff = now()->subSeconds((int) config('liveview.device_online_window_seconds'));

        LiveViewSession::withoutGlobalScopes()
            ->where('status', '!=', 'ended')
            ->whereNull('ended_at')
            ->where('last_heartbeat_at', '<', $cutoff)
            ->update([
                'status' => 'ended',
                'ended_at' => now(),
                'termination_reason' => 'heartbeat_timeout',
            ]);

        return self::SUCCESS;
    }
}
