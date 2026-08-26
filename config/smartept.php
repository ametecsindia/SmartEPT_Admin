<?php

return [

    // Force HTTPS on all API traffic. Keep false on Laragon local http://smartept.test;
    // set SMARTEPT_FORCE_HTTPS=true in production.
    'force_https' => env('SMARTEPT_FORCE_HTTPS', false),

    // Default per-company data retention window (days) applied when a company has none set.
    'default_retention_days' => (int) env('SMARTEPT_DEFAULT_RETENTION_DAYS', 90),

    // Object-store disk for screenshots/webcam photos. 'local' (storage/app) works on
    // Laragon out of the box; production points this at MinIO/S3/Azure/GCP.
    'storage_disk' => env('SMARTEPT_STORAGE_DISK', 'local'),

    // Agent device-token time-to-live in minutes. Null = non-expiring agent tokens.
    'agent_token_ttl' => env('SMARTEPT_AGENT_TOKEN_TTL') !== null && env('SMARTEPT_AGENT_TOKEN_TTL') !== ''
        ? (int) env('SMARTEPT_AGENT_TOKEN_TTL')
        : null,

    // Reserved integration endpoints (used from later milestones).
    'smartprs_base_url' => env('SMARTPRS_BASE_URL'),
    'smartdcm_base_url' => env('SMARTDCM_BASE_URL'),
    'license_url'       => env('SMARTEPT_LICENSE_URL'),
    // On-prem escape hatch: set SMARTEPT_LICENSE_VERIFY=false to skip TLS cert
    // verification on the licence phone-home (for local PCs with no CA bundle).
    'license_verify'    => filter_var(env('SMARTEPT_LICENSE_VERIFY', true), FILTER_VALIDATE_BOOLEAN),
    // EPT-29: offline node-locked licence file. Blank = license.lic in the app root.
    'license_file'      => env('SMARTEPT_LICENSE_FILE', ''),
    // SmartPRS2 rule (13-Aug-2026): licence gate on/off. Client packages ship true;
    // set SMARTEPT_LICENCE_ENFORCE=false on Ametecs' own demo/dev installs.
    'licence_enforce'   => env('SMARTEPT_LICENCE_ENFORCE', true),
    // True ONLY on client-hosted installs (the installer writes it): enables the
    // pre-login /activate .lic upload. The Ametecs cloud console leaves it false.
    'onprem'            => filter_var(env('SMARTEPT_ONPREM', false), FILTER_VALIDATE_BOOLEAN),

    // R2-2 ops alerts: minutes of heartbeat silence before a device is flagged
    // OFFLINE + admins emailed, and the violations-per-hour spike threshold.
    'offline_alert_minutes' => (int) env('SMARTEPT_OFFLINE_ALERT_MINUTES', 30),
    'violation_spike_threshold' => (int) env('SMARTEPT_VIOLATION_SPIKE_THRESHOLD', 20),

    // Section 10 single-device login: minutes of heartbeat silence after which an
    // agent session is considered STALE, so another PC may take over (a crashed /
    // permanently-disconnected PC never blocks the employee forever).
    'session_stale_minutes' => (int) env('SMARTEPT_SESSION_STALE_MINUTES', 10),

    // Enforcement: how long a tenant must spend in the LEARNING period before
    // blocking may be switched on.
    //
    // This is the control that stops a bad rollout — the report is what tells an
    // admin that Teams or Zoom would have been blocked. Short is convenient and
    // weak: twenty minutes only proves nobody has opened their usual programs
    // yet. Longer is inconvenient and honest.
    //
    // Set per deployment. For a regulated client, raise it — 4320 is three days,
    // which is what the auditor documentation describes.
    'min_audit_minutes' => (int) env('SMARTEPT_MIN_AUDIT_MINUTES', 20),

    // Policy resolution precedence (most specific first).
    'policy_precedence' => ['DEVICE', 'EMPLOYEE', 'TEAM', 'DEPARTMENT', 'BRANCH', 'COMPANY'],

    // Policy types the engine knows how to compose into the agent bundle.
    'policy_types' => [
        'MONITORING', 'SCREENSHOT', 'WEBCAM', 'APPLICATION', 'WEBSITE',
        'NETWORK', 'DEVICE', 'USB', 'VPN_PROXY', 'BREAK', 'ATTENDANCE', 'COMPLIANCE',
    ],
];
