<?php

namespace App\Services;

/**
 * Classifies an application or website against the effective policy and decides whether
 * it is blocked. The agent runs the same logic client-side for instant warnings; the
 * server re-evaluates on ingest so categorisation and compliance status are authoritative.
 */
class ComplianceEvaluator
{
    /**
     * @return array{category:string, blocked:bool, action:string}
     */
    public function classifyApp(?array $policy, string $appOrProcess): array
    {
        $needle = $this->normApp($appOrProcess);
        $action = strtoupper($policy['action_on_blocked'] ?? 'WARN');

        // Allowed wins over blocked: an explicitly-allowed app is never a violation,
        // so a team can keep a tool (e.g. AnyDesk) the company blocks generally.
        foreach ((array) ($policy['allowed_apps'] ?? []) as $a) {
            if ($needle !== '' && str_contains($needle, $this->normApp($a))) {
                return ['category' => 'PRODUCTIVE', 'blocked' => false, 'action' => $action];
            }
        }

        foreach ((array) ($policy['blocked_apps'] ?? []) as $b) {
            if ($needle !== '' && str_contains($needle, $this->normApp($b))) {
                return ['category' => 'BLOCKED', 'blocked' => true, 'action' => $action];
            }
        }

        // categories: { "chrome.exe": "PRODUCTIVE", ... }
        foreach ((array) ($policy['categories'] ?? []) as $key => $cat) {
            if ($needle !== '' && str_contains($needle, $this->normApp((string) $key))) {
                return ['category' => strtoupper($cat), 'blocked' => false, 'action' => $action];
            }
        }

        return ['category' => 'NEUTRAL', 'blocked' => false, 'action' => $action];
    }

    /**
     * @return array{category:string, blocked:bool, action:string}
     */
    public function classifyWebsite(?array $policy, ?string $domain, ?string $title): array
    {
        $hay = strtolower(trim(($domain ?? '') . ' ' . ($title ?? '')));
        $action = strtoupper($policy['action_on_blocked'] ?? 'WARN');

        // Allowed wins over blocked: an explicitly-allowed site is never a violation.
        foreach ((array) ($policy['allowed_sites'] ?? []) as $a) {
            if ($hay !== '' && str_contains($hay, $this->normSite($a))) {
                return ['category' => 'PRODUCTIVE', 'blocked' => false, 'action' => $action];
            }
        }

        foreach ((array) ($policy['blocked_sites'] ?? []) as $b) {
            if ($hay !== '' && $this->siteMatches($hay, $this->normSite($b))) {
                return ['category' => 'BLOCKED', 'blocked' => true, 'action' => $action];
            }
        }

        foreach ((array) ($policy['categories'] ?? []) as $key => $cat) {
            if ($hay !== '' && str_contains($hay, $this->normSite((string) $key))) {
                return ['category' => strtoupper($cat), 'blocked' => false, 'action' => $action];
            }
        }

        return ['category' => 'NEUTRAL', 'blocked' => false, 'action' => $action];
    }

    private function normApp(string $s): string
    {
        return trim(strtolower(preg_replace('/\.exe$/i', '', trim($s))));
    }

    /**
     * Browser titles rarely contain the domain ("YouTube - Google Chrome"), so in
     * addition to a plain substring match, match the entry's base name as a whole
     * word. Minimum 4 chars to avoid false positives from short entries like "x.com".
     */
    private function siteMatches(string $hay, string $entry): bool
    {
        if ($entry === '') {
            return false;
        }
        if (str_contains($hay, $entry)) {
            return true;
        }
        $base = explode('.', $entry)[0];
        if (strlen($base) < 4) {
            return false;
        }
        return (bool) preg_match('/\b' . preg_quote($base, '/') . '\b/i', $hay);
    }

    private function normSite(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('#^https?://#', '', $s);
        $s = preg_replace('#^www\.#', '', $s);
        return trim($s, '/ ');
    }
}
