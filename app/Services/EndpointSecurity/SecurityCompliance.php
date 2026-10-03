<?php

namespace App\Services\EndpointSecurity;

use App\Models\EndpointSecurityEvent;
use App\Models\EndpointSecurityStatus;
use App\Services\MailService;

/**
 * Security Compliance — deliberately separate from SmartEPT's productivity
 * "compliance" (ComplianceEvaluator / ScoringService), which it never touches.
 *
 * State comes from RULES, not from the score: one critical rule makes a machine
 * NON_COMPLIANT however high the score is. Unknown is UNKNOWN, never COMPLIANT.
 */
class SecurityCompliance
{
    public const COMPLIANT = 'COMPLIANT';
    public const ACTION_REQUIRED = 'ACTION_REQUIRED';
    public const NON_COMPLIANT = 'NON_COMPLIANT';
    public const UNKNOWN = 'UNKNOWN';

    // issue code => critical?
    public const ISSUES = [
        'SECURITY_NO_ACTIVE_AV' => true,
        'SECURITY_AV_DISABLED' => true,
        'SECURITY_THIRD_PARTY_NOT_ALLOWED' => true,
        'SECURITY_REALTIME_DISABLED' => true,
        'SECURITY_THREAT_UNRESOLVED' => true,
        'SECURITY_SIGNATURE_OUTDATED' => false,
        'SECURITY_FIREWALL_DISABLED' => false,
    ];

    /** @return array{0:string,1:string[],2:int|null} [state, issues, score] */
    public static function evaluate(EndpointSecurityStatus $s, array $policy): array
    {
        if (! $s->checked_at || $s->provider_type === 'unknown') {
            return [self::UNKNOWN, [], null];
        }

        $issues = [];
        if ($s->provider_type === 'none' && $policy['requireAntivirus']) {
            $issues[] = 'SECURITY_NO_ACTIVE_AV';
        }
        if ($s->provider_type === 'third_party' && ! $policy['allowThirdPartyAntivirus']) {
            $issues[] = 'SECURITY_THIRD_PARTY_NOT_ALLOWED';
        }
        if ($s->provider_type !== 'none' && $s->antivirus_enabled === false && $policy['requireAntivirus']) {
            $issues[] = 'SECURITY_AV_DISABLED';
        }
        if ($s->provider_type === 'defender' && $s->realtime_enabled === false && $policy['requireRealtimeProtection']) {
            $issues[] = 'SECURITY_REALTIME_DISABLED';
        }
        $maxAge = (int) $policy['maximumSignatureAgeHours'];
        if ($s->signature_outdated === true
            || ($maxAge > 0 && $s->signature_updated_at && $s->signature_updated_at->lt(now()->subHours($maxAge)))) {
            $issues[] = 'SECURITY_SIGNATURE_OUTDATED';
        }
        if ($policy['requireFirewall'] && in_array(false, [$s->firewall_domain, $s->firewall_private, $s->firewall_public], true)) {
            $issues[] = 'SECURITY_FIREWALL_DISABLED';
        }
        if ((int) $s->active_threat_count > 0) {
            $issues[] = 'SECURITY_THREAT_UNRESOLVED';
        }

        $critical = array_filter($issues, fn ($i) => self::ISSUES[$i]);
        $score = max(0, 100 - 40 * count($critical) - 15 * (count($issues) - count($critical)));
        $state = $critical ? self::NON_COMPLIANT : ($issues ? self::ACTION_REQUIRED : self::COMPLIANT);

        return [$state, array_values($issues), $score];
    }

    /** Read-time staleness, same convention as the Live Dashboard: computed, never written. */
    public static function displayState(EndpointSecurityStatus $s): string
    {
        $stale = ! $s->received_at || $s->received_at->lt(now()->subMinutes((int) config('endpoint_security.stale_minutes', 45)));

        return $stale ? self::UNKNOWN : $s->compliance;
    }

    /**
     * State-based alerts: open on Healthy -> Unhealthy, nothing while it stays
     * unhealthy, resolve on -> Healthy. One-shot alerts (threat detected,
     * provider changed) are passed in by the caller.
     *
     * @param string[] $oneShot
     */
    public static function applyAlerts(EndpointSecurityStatus $s, array $issues, array $oneShot, string $label): void
    {
        $open = (array) ($s->open_alerts ?? []);
        $opened = array_values(array_diff($issues, $open));
        $resolved = array_values(array_diff($open, $issues));

        foreach ($resolved as $code) {
            self::log($s, 'alert_resolved', $code);
        }
        foreach (array_merge($opened, $oneShot) as $code) {
            self::log($s, 'alert_opened', $code);
        }
        $s->open_alerts = array_values($issues);

        if ($opened || $oneShot) {
            self::email($s, array_merge($opened, $oneShot), $label);
        }
    }

    private static function log(EndpointSecurityStatus $s, string $kind, string $code): void
    {
        EndpointSecurityEvent::withoutGlobalScopes()->create([
            'company_id' => $s->company_id, 'enforcement_machine_id' => $s->enforcement_machine_id,
            'source' => 'server', 'kind' => $kind, 'detail' => $code, 'occurred_at' => now(),
        ]);
    }

    /** Off by default (Audit & Ops → Notifications → "Endpoint security"). Never throws. */
    private static function email(EndpointSecurityStatus $s, array $codes, string $label): void
    {
        try {
            if (! MailService::enabled('security_alert', (int) $s->company_id)) {
                return;
            }
            $vars = ['device' => $label, 'issues' => implode("\n", array_map(fn ($c) => '- ' . (self::LABELS[$c] ?? $c), $codes))];
            $t = MailService::TEMPLATES['security_alert'];
            foreach (array_keys(MailService::recipients('security_alert', (int) $s->company_id)) as $to) {
                MailService::send($to, MailService::render($t['subject'], $vars), MailService::render($t['body'], $vars), 'security_alert', (int) $s->company_id, $vars);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public const LABELS = [
        'SECURITY_NO_ACTIVE_AV' => 'No active antivirus',
        'SECURITY_AV_DISABLED' => 'Antivirus is turned off',
        'SECURITY_THIRD_PARTY_NOT_ALLOWED' => 'A third-party antivirus is active but not allowed by policy',
        'SECURITY_REALTIME_DISABLED' => 'Real-time protection is off',
        'SECURITY_SIGNATURE_OUTDATED' => 'Security intelligence (signatures) out of date',
        'SECURITY_FIREWALL_DISABLED' => 'Windows Firewall is off on at least one profile',
        'SECURITY_THREAT_UNRESOLVED' => 'A detected threat is not resolved',
        'SECURITY_THREAT_DETECTED' => 'Microsoft Defender detected a threat',
        'SECURITY_PROVIDER_CHANGED' => 'The active antivirus product changed',
    ];
}
