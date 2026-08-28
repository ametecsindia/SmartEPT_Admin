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

    // The mode a company starts in the FIRST time enforcement is touched (Ejaz, 26-Aug-2026:
    // "in the client package, the enforcement should be ON by default, no learning again on
    // client side"). The catalogue work — which apps to block, Store vs desktop identifiers,
    // the website categories, the BANKING profile — is already done and ships with the
    // package, so re-running it per client is pure delay.
    //
    // Default OFF, so an UPGRADE still never turns enforcement on for anybody (decision 4).
    // Only the client package's .env sets ENFORCE, and only for a brand-new install.
    //
    // ⚠ What starting at ENFORCE trades away: the learning report is what discovers the
    // programs THIS client runs from outside %WINDIR% and %PROGRAMFILES%. The first AppLocker
    // deny rule flips the collection to deny-by-default, so a business application living in
    // %LOCALAPPDATA% (Teams, VS Code, Slack, or the client's own CRM) will not launch until it
    // is allowed. Ship ENFORCE only where that estate is known.
    //
    // Recovery, if a program stops opening: Enforcement -> Disable in the console. It is the
    // kill switch, it is never gated, and endpoints drop their policy on the next heartbeat.
    'enforcement_default_mode' => env('SMARTEPT_ENFORCEMENT_DEFAULT', 'OFF'),

    // Is the LEARNING period (AUDIT mode) part of this installation at all?
    //
    // 27-Aug-2026 (Ejaz): "there should be no learning mechanism in the client. Whatever you
    // have learnt so far, implement that." Learning existed to discover which programs a given
    // client runs from outside %WINDIR% / %PROGRAMFILES%. That discovery is done, it ships in
    // the catalogue, and repeating it at each site produces the same answer a fortnight later.
    //
    // FALSE (the default, and what every client package ships with) means:
    //   - enforcement has exactly two states, ON and OFF — the console shows no third one;
    //   - `start-audit` and `promote` are refused, so nothing can write AUDIT;
    //   - a tenant found sitting in AUDIT (an older install) is answered OFF, because AUDIT
    //     blocks nothing and saying "learning" to an endpoint would restart the collection.
    //
    // TRUE restores the full OFF -> AUDIT -> ENFORCE gate. Keep it on Ametecs' own lab box
    // when surveying a NEW estate whose applications are genuinely unknown — that is the one
    // situation where the report tells you something the catalogue cannot.
    'enforcement_learning_enabled' => filter_var(
        env('SMARTEPT_ENFORCEMENT_LEARNING', false), FILTER_VALIDATE_BOOLEAN
    ),

    // Policy ASSIGNMENT precedence (most specific first). Documentation only — the chain
    // itself lives in PolicyResolver::assignableChain(), against the assignable_type enum.
    'policy_precedence' => ['DEVICE', 'EMPLOYEE', 'TEAM', 'DEPARTMENT', 'BRANCH', 'COMPANY'],

    // ENFORCEMENT precedence (most specific first) — a separate, shorter chain that decides
    // only whether a person is inside enforcement, and one level longer than the assignment
    // chain above. SHIFT sits between EMPLOYEE and TEAM (27-Aug-2026): a shift is chosen per
    // person, so it is more specific than the team they belong to, and "the night shift may
    // use the remote-support tool" has to beat "the support team is enforced", not lose to it.
    // Documentation only; PolicyResolver::effectiveEnforcementMode() is the implementation.
    'enforcement_precedence' => ['DEVICE', 'EMPLOYEE', 'SHIFT', 'TEAM', 'DEPARTMENT', 'BRANCH', 'COMPANY'],

    // Policy types the engine knows how to compose into the agent bundle.
    'policy_types' => [
        'MONITORING', 'SCREENSHOT', 'WEBCAM', 'APPLICATION', 'WEBSITE',
        'NETWORK', 'DEVICE', 'USB', 'VPN_PROXY', 'BREAK', 'ATTENDANCE', 'COMPLIANCE',
    ],
];
