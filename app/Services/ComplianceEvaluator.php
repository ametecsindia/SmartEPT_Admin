<?php

namespace App\Services;

/**
 * Classifies an application or website against the effective policy and decides whether
 * it is blocked. The agent runs the same logic client-side for instant warnings; the
 * server re-evaluates on ingest so categorisation and compliance status are authoritative.
 *
 * Two changes, 21-Aug-2026:
 *
 * 1. PER-RULE ACTIONS. `action_on_blocked` lives on the POLICY, so one action covered
 *    every blocked item in a tenant — "warn on Steam, close WhatsApp" could not be
 *    expressed at all. When the bundle carries a `rules` list (policy_rules), the
 *    matched item's own action is returned. Without one, behaviour is exactly as
 *    before: the policy-level action for everything. Old agents are unaffected.
 *
 * 2. `web.whatsapp.com` NEVER MATCHED. siteMatches fell back to the entry's FIRST
 *    dot-label and required 4 characters, so `web.whatsapp.com` reduced to `web`,
 *    was rejected, and only a literal substring could match. Browser titles read
 *    "WhatsApp - Google Chrome" and never contain the full domain, so the entry was
 *    dead. It now skips common host prefixes before taking the label, which is how
 *    `web.whatsapp.com` becomes `whatsapp`. Short entries like `x.com` still require
 *    the literal substring — that guard was deliberate and stays.
 */
class ComplianceEvaluator
{
    /**
     * Host labels that are never the significant part of a domain. Taking the
     * first label blindly is what killed web.whatsapp.com.
     */
    private const HOST_PREFIXES = ['www', 'web', 'm', 'app', 'apps', 'mail', 'login', 'secure', 'my', 'chat', 'desktop'];

    /**
     * Words that appear in the browser's own name, and therefore in EVERY browser
     * window title. Matching one as a whole word would flag every tab the employee
     * has ever opened: "mail.google.com" reduces to "google", and every Chrome
     * title ends in "Google Chrome". Entries whose significant label is one of
     * these fall back to requiring the literal domain.
     *
     * Found by a test in the agent's mirror of this logic on 21-Aug-2026 —
     * the naive version of the web.whatsapp.com fix introduced it.
     */
    private const BROWSER_WORDS = ['google', 'chrome', 'chromium', 'mozilla', 'firefox', 'microsoft', 'edge', 'brave', 'opera', 'safari', 'internet', 'explorer', 'browser'];

    /**
     * @return array{category:string, blocked:bool, action:string, rule:?array}
     */
    public function classifyApp(?array $policy, string $appOrProcess): array
    {
        $needle = $this->normApp($appOrProcess);
        $action = strtoupper($policy['action_on_blocked'] ?? 'WARN');
        $rules  = $this->rulesByItem($policy, 'APPLICATION');

        // Allowed wins over blocked: an explicitly-allowed app is never a violation,
        // so a team can keep a tool (e.g. AnyDesk) the company blocks generally.
        foreach ((array) ($policy['allowed_apps'] ?? []) as $a) {
            if ($needle !== '' && str_contains($needle, $this->normApp($a))) {
                return $this->verdict('PRODUCTIVE', false, $action, null);
            }
        }

        foreach ((array) ($policy['blocked_apps'] ?? []) as $b) {
            $entry = $this->normApp($b);
            if ($needle !== '' && str_contains($needle, $entry)) {
                $rule = $rules[$entry] ?? null;

                return $this->verdict('BLOCKED', true, $this->actionFor($rule, $action), $rule);
            }
        }

        // categories: { "chrome.exe": "PRODUCTIVE", ... }
        foreach ((array) ($policy['categories'] ?? []) as $key => $cat) {
            if ($needle !== '' && str_contains($needle, $this->normApp((string) $key))) {
                return $this->verdict(strtoupper($cat), false, $action, null);
            }
        }

        return $this->verdict('NEUTRAL', false, $action, null);
    }

    /**
     * @return array{category:string, blocked:bool, action:string, rule:?array}
     */
    public function classifyWebsite(?array $policy, ?string $domain, ?string $title): array
    {
        $hay    = strtolower(trim(($domain ?? '') . ' ' . ($title ?? '')));
        $action = strtoupper($policy['action_on_blocked'] ?? 'WARN');
        $rules  = $this->rulesByItem($policy, 'WEBSITE');

        // Allowed wins over blocked: an explicitly-allowed site is never a violation.
        foreach ((array) ($policy['allowed_sites'] ?? []) as $a) {
            if ($hay !== '' && str_contains($hay, $this->normSite($a))) {
                return $this->verdict('PRODUCTIVE', false, $action, null);
            }
        }

        foreach ((array) ($policy['blocked_sites'] ?? []) as $b) {
            $entry = $this->normSite($b);
            if ($hay !== '' && $this->siteMatches($hay, $entry)) {
                $rule = $rules[$entry] ?? null;

                return $this->verdict('BLOCKED', true, $this->actionFor($rule, $action), $rule);
            }
        }

        foreach ((array) ($policy['categories'] ?? []) as $key => $cat) {
            if ($hay !== '' && str_contains($hay, $this->normSite((string) $key))) {
                return $this->verdict(strtoupper($cat), false, $action, null);
            }
        }

        return $this->verdict('NEUTRAL', false, $action, null);
    }

    /**
     * @param array<string,mixed>|null $rule
     * @return array{category:string, blocked:bool, action:string, rule:?array}
     */
    private function verdict(string $category, bool $blocked, string $action, ?array $rule): array
    {
        return [
            'category' => $category,
            'blocked'  => $blocked,
            'action'   => $action,
            // The matched rule travels with the verdict so the caller can record
            // WHICH rule fired, not just that something did. Without it a
            // compliance event names a program and nothing else, and an auditor
            // cannot tie it back to a control.
            'rule'     => $rule,
        ];
    }

    /**
     * The matched rule's own action, falling back to the policy-level one.
     *
     * @param array<string,mixed>|null $rule
     */
    private function actionFor(?array $rule, string $policyAction): string
    {
        $action = strtoupper((string) ($rule['action'] ?? ''));

        return $action !== '' ? $action : $policyAction;
    }

    /**
     * Index the bundle's per-rule list by its normalised item, so a match in the
     * blocked list can find its own row in one lookup.
     *
     * @return array<string,array<string,mixed>>
     */
    private function rulesByItem(?array $policy, string $type): array
    {
        $out = [];
        foreach ((array) ($policy['rules'] ?? []) as $rule) {
            if (! is_array($rule) || ! isset($rule['item'])) {
                continue;
            }
            $item = $type === 'APPLICATION'
                ? $this->normApp((string) $rule['item'])
                : $this->normSite((string) $rule['item']);
            if ($item !== '') {
                $out[$item] = $rule;
            }
        }

        return $out;
    }

    private function normApp(string $s): string
    {
        return trim(strtolower(preg_replace('/\.exe$/i', '', trim($s))));
    }

    /**
     * Browser titles rarely contain the domain ("YouTube - Google Chrome"), so in
     * addition to a plain substring match, match the entry's significant label as a
     * whole word.
     *
     * "Significant" is not the first label. web.whatsapp.com's first label is "web",
     * which is meaningless and under the 4-character floor, so the entry matched
     * nothing at all until 21-Aug-2026. Skip known host prefixes first.
     *
     * The 4-character floor itself stays: it is what stops "x.com" matching the
     * letter x anywhere in a window title. Entries that short still need the
     * literal domain to appear.
     */
    private function siteMatches(string $hay, string $entry): bool
    {
        if ($entry === '') {
            return false;
        }
        if (str_contains($hay, $entry)) {
            return true;
        }

        $label = $this->significantLabel($entry);
        if (strlen($label) < 4) {
            return false;
        }
        if (in_array($label, self::BROWSER_WORDS, true)) {
            return false;
        }

        return (bool) preg_match('/\b' . preg_quote($label, '/') . '\b/i', $hay);
    }

    /**
     * The part of a hostname a human would call "the site".
     *
     *   web.whatsapp.com  -> whatsapp
     *   www.youtube.com   -> youtube
     *   mail.google.com   -> google
     *   x.com             -> x        (too short, caller rejects it)
     *   facebook          -> facebook
     */
    private function significantLabel(string $entry): string
    {
        $labels = array_values(array_filter(explode('.', $entry), static fn ($l) => $l !== ''));
        if ($labels === []) {
            return '';
        }

        foreach ($labels as $label) {
            if (! in_array($label, self::HOST_PREFIXES, true)) {
                return $label;
            }
        }

        // Every label was a prefix word (e.g. "www.web"). Fall back to the first.
        return $labels[0];
    }

    private function normSite(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('#^https?://#', '', $s);
        $s = preg_replace('#^www\.#', '', $s);

        return trim($s, '/ ');
    }
}
