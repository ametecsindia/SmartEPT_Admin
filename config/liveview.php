<?php

// New, isolated config file — nothing existing reads or references this.
// Nothing to add to smartept's .env: the relay secret defaults to the app's own
// APP_KEY (already set). Override LIVEVIEW_RELAY_SECRET / LIVEVIEW_RELAY_URL only
// if you need something else.
//
// 15-Sep-2026: relay_url used to default to a hard-coded ws://127.0.0.1:8098 —
// only reachable when the browser/Agent runs on the SAME machine as the relay.
// Found live: an employee's Agent on a different PC sat on "Connected. Waiting
// for the Agent to start sending frames…" forever, because ITS 127.0.0.1 has
// nothing listening on port 8098 — only the server's does.
//
// 15-Sep-2026 (second fix, same day): the first fix above derived the host from
// APP_URL instead — better, but still ONE fixed value for every caller, and a
// genuinely different PC (Agent or admin browser) reaches this server by a
// DIFFERENT host than APP_URL (a LAN IP, say) than the server's own machine
// does. Deriving from APP_URL still shipped "smartept.test" to a client that
// can't resolve it — Agent stream never connected, admin browser view leg
// closed immediately ("Relay connection closed"). Fixed at the root: relay_url
// is no longer computed here at all — LiveViewSession::relayUrl() derives it
// PER REQUEST from that request's own Host header, so it's always whatever
// host the caller already proved they can reach. LIVEVIEW_RELAY_URL below is
// now only an explicit override (e.g. a custom domain in production where the
// relay sits behind its own reverse proxy) — leave it unset for normal LAN use.
// Still ws:// (not wss://) unconditionally — the relay binary itself has no TLS
// support yet; that's a separate, bigger piece of work.
return [
    'relay_secret' => env('LIVEVIEW_RELAY_SECRET', config('app.key')),
    'relay_url'    => env('LIVEVIEW_RELAY_URL'),
    'relay_port'   => env('RELAY_PORT', 8098),

    // How long a minted session token is valid for BEFORE the leg connects. Not the
    // session's own duration — once connected, the socket just stays open (Phase 3
    // POC has no heartbeat-timeout sweep yet; see the migration's docblock).
    'token_ttl_seconds' => 60,

    // How stale an Agent's last heartbeat may be before it's treated as offline for
    // the "is this employee reachable at all" check in LiveViewController::start().
    'device_online_window_seconds' => 90,
];
