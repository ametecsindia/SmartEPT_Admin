<?php

/*
 * SmartEPT Endpoint Security (Oct-2026) — Microsoft Defender monitoring & management.
 * Enforcer + Commander only. See docs\SmartEPT-Endpoint-Security-Technical.md.
 */
return [
    // Rollout switch. OFF by default: until SMARTEPT_ENDPOINT_SECURITY=true the nav is
    // hidden, console routes refuse, and endpoints are told to idle. Turning it off again
    // is the instant rollback — nothing else in SmartEPT reads this.
    'enabled' => (bool) env('SMARTEPT_ENDPOINT_SECURITY', false),

    // Plan level -> capabilities. Level comes from the licence (Entitlement::level).
    'capabilities' => [
        'basic' => ['monitoring', 'threat_history', 'compliance', 'refresh', 'quick_scan', 'signature_update', 'reports'],
        'advanced' => ['monitoring', 'threat_history', 'compliance', 'refresh', 'quick_scan', 'signature_update', 'reports',
            'full_scan', 'custom_scan', 'policies', 'fleet_advanced', 'command_history', 'events', 'advanced_reports'],
    ],

    // Endpoint cadence, sent to the service on every sync (seconds). Jitter spreads
    // thousands of PCs so they never call in together.
    'intervals' => [
        'status' => (int) env('SMARTEPT_ES_STATUS_SECONDS', 600),
        'events' => (int) env('SMARTEPT_ES_EVENTS_SECONDS', 300),
        'poll'   => (int) env('SMARTEPT_ES_POLL_SECONDS', 60),
        'jitter' => (int) env('SMARTEPT_ES_JITTER_SECONDS', 60),
    ],

    // A command not picked up within this many minutes expires (the endpoint refuses it too).
    'command_ttl_minutes' => 60,

    // No security report for this long = "Unable to verify" (computed at read time).
    'stale_minutes' => 45,

    // Defender events kept per machine.
    'event_retention_days' => 180,

    // Default Security Compliance policy; each company may override (Commander).
    'policy_defaults' => [
        'requireAntivirus' => true,
        'requireRealtimeProtection' => true,
        'maximumSignatureAgeHours' => 48,
        'requireFirewall' => true,
        'allowThirdPartyAntivirus' => true,
        'pathRedaction' => 'REDACT_USER', // FULL_PATH | REDACT_USER | FILENAME_ONLY | NO_PATH
    ],
];
