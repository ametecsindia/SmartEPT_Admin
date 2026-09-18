<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The concurrency ledger AND the audit trail — one row per LiveView session,
 * never a maintained counter (see the migration's docblock / plan §5.1 & §11).
 */
class LiveViewSession extends Model
{
    use BelongsToCompany;

    // Bug fix (13-Sep-2026): without this, Eloquent guesses the table name from the
    // class name ("LiveViewSession" -> "live_view_sessions"), but the migration
    // creates "liveview_sessions" (matching the rest of the plan's naming, e.g.
    // config/liveview.php, the liveview.* permission slugs). Every query 500'd with
    // "Base table or view not found" until this was pinned explicitly.
    protected $table = 'liveview_sessions';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at'        => 'datetime',
        'ended_at'           => 'datetime',
        'last_heartbeat_at' => 'datetime',
    ];

    public function employee()  { return $this->belongsTo(Employee::class); }
    public function adminUser() { return $this->belongsTo(User::class, 'admin_user_id'); }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * The one query both the ~30s device heartbeat and the fast LiveView-only
     * poll (15-Sep-2026, see LiveViewController::poll()) use to find "does this
     * device have a stream to open right now" — kept in one place so the two
     * callers can never drift out of sync on what "requested" means.
     *
     * 15-Sep-2026 (later same day): used to be ->first() — only the SINGLE
     * newest 'connecting' row. Fine while one employee's PC only ever streamed
     * one monitor, but the admin console's own "Start All" / per-monitor tiles
     * let the same device have two (or more) 'connecting' rows at once, and
     * ->first() silently starved every one but the newest — that session's tile
     * sat on "Connected. Waiting for the Agent to start sending frames…" forever,
     * because the Agent was never even told it existed. Found live 15-Sep-2026:
     * Desktop 1 and Desktop 2 both started for the same employee, only Desktop 2
     * (started later) ever went LIVE. Now returns every connecting session for
     * this device, so the Agent (see main.js's applyLiveViewRequests) can stream
     * all of them at once, same as the admin console already assumes it can.
     */
    public static function connectingFor(string $deviceUuid): \Illuminate\Support\Collection
    {
        return static::where('device_uuid', $deviceUuid)
            ->where('status', 'connecting')
            ->whereNull('ended_at')
            ->latest('id')
            ->get();
    }

    /** The response block main.js needs to start capturing — mints a fresh stream
     * token and bumps last_heartbeat_at (this is EndStaleLiveViewSessions's pulse). */
    public function toAgentRequest(\App\Services\LiveViewTokenService $tokens): array
    {
        $this->forceFill(['last_heartbeat_at' => now()])->save();

        return [
            'session_id'    => $this->id,
            'token'         => $tokens->mint($this->id, 'stream'),
            'relay_url'     => static::relayUrl(),
            'monitor_index' => $this->monitor_index,
            'quality'       => $this->quality,
        ];
    }

    /**
     * 15-Sep-2026: NOT config('liveview.relay_url') derived from a fixed APP_URL —
     * that was the previous fix and it was still wrong, because APP_URL is only ONE
     * host, and every caller (Agent or admin browser) may reach this server by a
     * different one: smartept.test on the server's own machine, a LAN IP from
     * another PC, a public domain from outside. Whichever host got THIS request here
     * is guaranteed reachable by whoever sent it, and the relay is the same box, just
     * a different port — so that's what both the Agent's stream leg (toAgentRequest,
     * above) and the admin browser's view leg (LiveViewController::start()) now use.
     * LIVEVIEW_RELAY_URL stays as an explicit override for setups where that isn't
     * true (e.g. the relay sits behind its own reverse-proxy domain in production).
     */
    public static function relayUrl(): string
    {
        return config('liveview.relay_url') ?: 'ws://' . request()->getHost() . ':' . config('liveview.relay_port');
    }
}
