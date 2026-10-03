<?php

namespace App\Services\EndpointSecurity;

use App\Models\Company;
use App\Models\InstallationLicense;

/**
 * Which Endpoint Security level a company's plan includes: none | basic | advanced.
 *
 * Reads the EXISTING licence bundle — no second licensing system:
 *   1. an explicit features.endpoint_security from Central wins (true|'basic'|'advanced'|false);
 *   2. else the plan tier: commander -> advanced, enforcer -> basic, standard -> none;
 *   3. else (licences issued before tiers) the existing features:
 *      enforcement + live_view -> advanced, enforcement -> basic.
 * Standard never gets it. Any failure answers NONE — a new feature fails CLOSED
 * (the rest of the console is unaffected either way).
 */
class Entitlement
{
    public const NONE = 'none';
    public const BASIC = 'basic';
    public const ADVANCED = 'advanced';

    public static function level(?Company $company): string
    {
        if (! config('endpoint_security.enabled')) {
            return self::NONE;
        }
        try {
            $lic = InstallationLicense::governing($company);
            $features = $lic->bundle['features'] ?? null;
            if (is_array($features) && array_key_exists('endpoint_security', $features)) {
                $v = $features['endpoint_security'];

                return in_array($v, [self::BASIC, self::ADVANCED], true) ? $v : ($v === true ? self::BASIC : self::NONE);
            }

            return match ($lic->tier()) {
                'commander' => self::ADVANCED,
                'enforcer' => self::BASIC,
                'standard' => self::NONE,
                default => $lic->hasFeature('enforcement')
                    ? ($lic->hasFeature('live_view') ? self::ADVANCED : self::BASIC)
                    : self::NONE,
            };
        } catch (\Throwable $e) {
            return self::NONE;
        }
    }

    /** @return string[] */
    public static function capabilities(?Company $company): array
    {
        $level = self::level($company);

        return $level === self::NONE ? [] : (array) config("endpoint_security.capabilities.$level", []);
    }

    public static function can(?Company $company, string $capability): bool
    {
        return in_array($capability, self::capabilities($company), true);
    }
}
