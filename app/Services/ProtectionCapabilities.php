<?php

namespace App\Services;

use App\Models\PolicyRule;

/**
 * Answers one question, for the console and for the API: can SmartEPT actually
 * enforce this protection on this item, and if not, why not?
 *
 * The matrix itself lives in config/protections.php. This class is the only
 * thing that reads it, so the shape of that file is a private detail and the
 * "_inherit" indirection never leaks into a Blade template or a JSON payload.
 *
 * It is deliberately advisory. Nothing here proves a block happened — that is
 * the endpoint's report, which carries explicit NOT_ENFORCEABLE values. This
 * exists so the console stops offering controls that do nothing, which is the
 * failure the client called out by name.
 */
class ProtectionCapabilities
{
    /** Offered on screen. Anything else is hidden, not disabled-with-a-reason. */
    public const OFFERED = ['SUPPORTED', 'BROWSER_WIDE', 'UNVERIFIED'];

    /**
     * The full capability answer for one item.
     *
     * @return array<string,array{status:string,mechanism:?string,note:?string,where:?string,blind_spots:array<int,string>}>
     */
    public function forItem(string $item, string $type): array
    {
        $type = strtoupper($type) === 'WEBSITE' ? 'WEBSITE' : 'APPLICATION';
        $key  = $this->normalise($item, $type);

        $defaults = (array) config("protections.defaults.$type", []);
        $entry    = (array) (config('protections.items', [])[$key] ?? []);

        // "_inherit" means "this item is a plain example of its type" — it is
        // listed only so the console can say something useful about it without
        // repeating the same four lines seven times.
        if (isset($entry['_inherit'])) {
            $entry = [];
        }

        $out = [];
        foreach (PolicyRule::PROTECTIONS as $protection) {
            $spec = (array) ($entry[$protection] ?? $defaults[$protection] ?? ['status' => 'UNSUPPORTED']);
            $mechanism = $spec['mechanism'] ?? null;
            $mech = $mechanism ? (array) (config('protections.mechanisms', [])[$mechanism] ?? []) : [];

            $out[$protection] = [
                'status'      => (string) ($spec['status'] ?? 'UNSUPPORTED'),
                'mechanism'   => $mechanism,
                'note'        => $spec['note'] ?? ($mech['blind_spots'][0] ?? null),
                'where'       => $mech['where'] ?? null,
                'blind_spots' => array_values((array) ($mech['blind_spots'] ?? [])),
            ];
        }

        return $out;
    }

    /** Can this protection be offered at all for this item? */
    public function isOffered(string $item, string $type, string $protection): bool
    {
        $status = $this->forItem($item, $type)[$protection]['status'] ?? 'UNSUPPORTED';

        return in_array($status, self::OFFERED, true);
    }

    /**
     * Everything the console needs to render the protection controls, in one
     * response: the labels, the mechanisms with their blind spots, the defaults
     * per rule type, and the per-item overrides.
     *
     * Sent whole rather than queried per row because the Rules screen renders
     * every row at once and a request per row would be a request per
     * application on a floor with fifty of them.
     *
     * @return array<string,mixed>
     */
    public function forConsole(): array
    {
        $items = [];
        foreach ((array) config('protections.items', []) as $key => $entry) {
            if (isset($entry['_inherit'])) {
                continue;
            }
            $items[(string) $key] = $this->forItem((string) $key, $this->typeOf((string) $key));
        }

        return [
            'protections' => (array) config('protections.catalogue', []),
            'mechanisms'  => (array) config('protections.mechanisms', []),
            'defaults'    => [
                'APPLICATION' => $this->forItem('__default__', 'APPLICATION'),
                'WEBSITE'     => $this->forItem('__default__', 'WEBSITE'),
            ],
            'items'       => $items,
        ];
    }

    /** A listed key that looks like a host is a website rule; everything else is an app. */
    private function typeOf(string $key): string
    {
        return str_contains($key, '.') ? 'WEBSITE' : 'APPLICATION';
    }

    /** Must match PolicyRuleController::normalise and ComplianceEvaluator. */
    private function normalise(string $s, string $type): string
    {
        $s = mb_strtolower(trim($s));
        if ($type === 'APPLICATION') {
            return trim((string) preg_replace('/\.exe$/i', '', $s));
        }
        $s = (string) preg_replace('#^https?://#', '', $s);
        $s = (string) preg_replace('#^www\.#', '', $s);

        return trim($s, '/ ');
    }
}
