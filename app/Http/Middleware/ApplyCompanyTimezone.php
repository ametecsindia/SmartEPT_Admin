<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API request runs on the timezone set in Organisation → Company for the signed-in user's
 * company — agent and admin console alike (Ejaz, 26-Aug and again 23-Sep-2026: "the time zone set
 * in the Admin console for the organisation should be considered, NOT UTC or server time").
 *
 * AppServiceProvider::applyOrganisationTimezone() already does this at boot, but ONLY when the
 * install holds exactly one company. On a multi-tenant server (admin.smartept.com) it switched
 * itself off and every now() fell back to APP_TIMEZONE — server time. This closes that gap per
 * request: the company is known once the bearer token resolves, so its clock is used.
 *
 * Restored after the response so nothing leaks into the next request/test in the same process.
 */
class ApplyCompanyTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $original = config('app.timezone');

        try {
            $companyId = $request->user('sanctum')?->company_id;
            // ponytail: one indexed lookup per request, no cache — so a change in the Organisation
            // tab applies on the very next request with nothing to invalidate.
            $tz = $companyId ? (string) Company::withoutGlobalScopes()->whereKey($companyId)->value('timezone') : '';

            if ($tz && $tz !== $original && in_array($tz, timezone_identifiers_list(), true)) {
                config(['app.timezone' => $tz]);
                date_default_timezone_set($tz);
            }
        } catch (\Throwable $e) {
            // No token, no DB, bad value — keep the default clock; never fail the request.
        }

        try {
            return $next($request);
        } finally {
            config(['app.timezone' => $original]);
            date_default_timezone_set($original);
        }
    }
}
