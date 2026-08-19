<?php

namespace App\Http\Middleware;

use App\Models\InstallationLicense;
use App\Services\LicenseClient;
use Closure;
use Illuminate\Http\Request;

/**
 * R2-1: gate the console on the licence.
 *
 * Per-tenant licensing (12-Aug-2026): the licence that gates a request is the
 * one governing the requesting user's company — a cloud tenant (AMETECS_SAAS)
 * is judged ONLY on its own licence row, so one tenant's expiry can never
 * block another tenant on the shared install. Everyone else keeps the
 * install-level licence, exactly as before.
 *
 * 14-Aug-2026 (Ejaz, finding 1.5) — THE BLOCK IS NOW THE WHOLE CONSOLE.
 * Until today this middleware sat on `api/agent/*` only, so an expired licence
 * stopped the agents uploading while every human screen carried on working:
 * a client whose licence had expired AND whose 7 grace days had run out could
 * still sign in and use SmartEPT normally. It now runs on the entire
 * authenticated API, with one deliberate hole:
 *
 *   - Anyone may still call auth/me, auth/logout, auth/refresh and
 *     change-password — a blocked user has to be able to see why and sign out.
 *   - A Super Admin or Company Admin may additionally reach the Licence screen
 *     (view/save/validate/import a key) and Help → Troubleshooting. That is the
 *     rescue route: the person who can fix it can always get in and fix it.
 *   - Everything else answers 403 LICENSE_BLOCKED, and the console shows the
 *     licence wall instead of the app.
 *
 * Unchanged safety rails: licence_enforce=false keeps demo/internal installs
 * open, and any throw inside the licence machinery FAILS SOFT.
 */
class EnsureLicensed
{
    /** Reachable by ANY signed-in user while blocked — see why, change password, sign out. */
    private const ALWAYS_ALLOWED = [
        'api/auth/me',
        'api/auth/logout',
        'api/auth/refresh',
        'api/auth/change-password',
    ];

    /** Reachable by an admin while blocked — the rescue route back to a working licence. */
    private const ADMIN_RESCUE = [
        'api/license',
        'api/license/validate',
        'api/license/import',
        'api/ops/diagnostics',
        'api/ops/logs',
    ];

    private const RESCUE_ROLES = ['SUPER_ADMIN', 'COMPANY_ADMIN'];

    public function __construct(private LicenseClient $client)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        // SmartPRS2 rules (13-Aug-2026): (a) licence_enforce=false keeps demo /
        // internal installs open; (b) FAIL-SOFT — an internal error inside the
        // licence machinery must never take a client's monitoring down, so any
        // throw lets the request through (real licence verdicts still block).
        // licence_enforce=false (demo/internal), OR the developer toggle file for this
        // machine (php artisan smartept:licence off). The toggle is keyed to the machine
        // fingerprint and excluded from client builds — see App\Services\DevLicenceKey.
        if (! \App\Services\DevLicenceKey::enforcementOn()) {
            return $next($request);
        }

        try {
            $license = InstallationLicense::governing($request->user()?->company);

            // A licence that came from a signed .lic file is self-authoritative: the
            // signature and the machine binding ARE the proof, so there is nothing for
            // Central to add. Skipping the phone-home here fixes two things for
            // on-premise clients (Ejaz, 19-Aug-2026):
            //   1. a fully paid offline install could be BRICKED by a *successful*
            //      call — if Central did not recognise the key it overwrote the good
            //      .lic verdict with 'unknown_key' and blocked the whole console;
            //   2. the first request after midnight paid a ~10s synchronous timeout
            //      on a server that has no internet by design.
            if ($license->configured() && ! $license->fromFile()) {
                $license = $this->client->ensureFresh($license);
            }
        } catch (\Throwable $e) {
            return $next($request);
        }

        if ($license->operational()) {
            return $next($request);
        }

        if ($this->isAllowedWhileBlocked($request)) {
            return $next($request);
        }

        // Ejaz's rule: 7-day evaluation, then block everything until a key is entered.
        $reason = $license->configured() ? $license->status : 'evaluation_expired';

        return response()->json([
            'error' => [
                'code' => 'LICENSE_BLOCKED',
                'reason' => $reason,
                'message' => $this->message($license, $reason),
                'admin_can_fix' => $this->isRescueUser($request),
            ],
        ], 403);
    }

    private function isAllowedWhileBlocked(Request $request): bool
    {
        // The shared Ametecs cloud install: SUPER_ADMIN is OUR operator account,
        // not a client. Every cloud tenant carries its OWN licence row there and
        // the install-level row has no key of its own — locking the operator out
        // of the tenant list would be a self-inflicted outage.
        //
        // The test is the INSTALL, not the env flag: a server that carries its own
        // install-level licence key is a client server, so its SUPER_ADMIN is the
        // client's own owner and gets only the rescue routes like any other admin.
        // (SMARTEPT_ONPREM also forces that, but old installs may not have it set.)
        if ($request->user()?->roleSlug() === 'SUPER_ADMIN'
            && ! filter_var(config('smartept.onprem', false), FILTER_VALIDATE_BOOLEAN)
            && ! InstallationLicense::current()->configured()) {
            return true;
        }

        $path = trim($request->path(), '/');

        if (in_array($path, self::ALWAYS_ALLOWED, true)) {
            return true;
        }

        return $this->isRescueUser($request) && in_array($path, self::ADMIN_RESCUE, true);
    }

    private function isRescueUser(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && in_array($user->roleSlug(), self::RESCUE_ROLES, true);
    }

    /** Plain-English, and honest about who has to do what. */
    private function message(InstallationLicense $license, string $reason): string
    {
        if (! $license->configured()) {
            return 'The 7-day evaluation period has ended. A Company Admin must enter the SmartEPT licence key on the Licence screen before the system can be used again.';
        }

        return match ($reason) {
            'expired' => 'This SmartEPT licence expired on ' . optional($license->expiresAt())->toDateString()
                . ' and the ' . $license->graceDays() . '-day grace period has also ended. Renew the licence and enter the new key on the Licence screen to restore access.',
            'suspended' => 'This SmartEPT licence has been suspended by Ametecs. Please contact Ametecs to restore access.',
            'revoked' => 'This SmartEPT licence has been revoked. Please contact Ametecs.',
            'superseded' => 'This licence key was replaced when your paid subscription was activated. Enter the new key from your order email on the Licence screen.',
            'unknown_key' => 'The licence key on this server is not recognised by SmartEPT Central. Check the key on the Licence screen, or contact Ametecs.',
            'server_mismatch' => 'This licence is registered to a different server. Contact Ametecs to move it to this machine.',
            default => 'This SmartEPT server\'s licence is not active (' . $reason . '). Ask your administrator to renew it on the Licence screen.',
        };
    }
}
