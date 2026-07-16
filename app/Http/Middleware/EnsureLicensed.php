<?php

namespace App\Http\Middleware;

use App\Models\InstallationLicense;
use App\Services\LicenseClient;
use Closure;
use Illuminate\Http\Request;

/**
 * R2-1: gate agent traffic on the installation licence.
 *
 * - No key configured → pass (unlicensed/dev install, flagged on the Licence screen).
 * - Cached verdict is refreshed at most daily (lock-guarded, never hammers Central).
 * - Blocked only when Central said revoked/suspended/unknown/mismatch, or the
 *   licence is expired BEYOND its grace window. Console/reads stay available.
 */
class EnsureLicensed
{
    public function __construct(private LicenseClient $client)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $license = InstallationLicense::current();

        if ($license->configured()) {
            $license = $this->client->ensureFresh($license);
        }

        if (! $license->operational()) {
            // Ejaz's rule: 7-day evaluation, then block everything until a key is entered.
            $reason = $license->configured() ? $license->status : 'evaluation_expired';
            $message = $license->configured()
                ? 'This SmartEPT server\'s licence is not active (' . $license->status . '). Ask your administrator to renew it on the Licence screen.'
                : 'The 7-day evaluation period has ended. Ask your administrator to enter the licence key on the Licence screen to resume monitoring.';

            return response()->json([
                'error' => ['code' => 'LICENSE_BLOCKED', 'reason' => $reason, 'message' => $message],
            ], 403);
        }

        return $next($request);
    }
}
