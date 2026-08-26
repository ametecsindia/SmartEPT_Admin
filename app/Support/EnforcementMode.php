<?php

namespace App\Support;

/**
 * Whether one person's PC actually blocks anything.
 *
 * Two values and a null, deliberately. "Enforced" and "Exempt" say what happens;
 * NULL says "whatever the level above says". Anything richer — per-application
 * exemptions, time-of-day rules — belongs in the rules themselves, not in a
 * switch an administrator reads at a glance.
 *
 * ENFORCED is not "blocking". Nothing blocks until the TENANT is switched to
 * ENFORCE and the learning period is clean. This value only decides whether a
 * person is inside that or outside it.
 */
final class EnforcementMode
{
    /** This person's PCs apply the company's blocking rules. */
    public const ENFORCED = 'ENFORCED';

    /** This person is outside enforcement. Their sign-in blocks nothing. */
    public const EXEMPT = 'EXEMPT';

    /** @var list<string> */
    public const ALL = [self::ENFORCED, self::EXEMPT];

    /**
     * The safe answer when nothing anywhere in the hierarchy has an opinion.
     *
     * ENFORCED, not EXEMPT. An unset value must never be the quiet way an
     * employee ends up outside the controls the client is paying for — and it
     * cannot start blocking anything on its own, because the tenant switch still
     * gates everything.
     */
    public const DEFAULT = self::ENFORCED;

    /** Normalise a stored value, or null when it is empty or unrecognised. */
    public static function clean(mixed $value): ?string
    {
        $v = strtoupper(trim((string) $value));

        return in_array($v, self::ALL, true) ? $v : null;
    }
}
