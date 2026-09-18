<?php

namespace App\Http\Middleware;

use App\Models\InstallationLicense;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standard / Enforcer / Commander plans (18-Sep-2026).
 *
 * Route guard: `->middleware('feature:enforcement')` or `feature:live_view`.
 * Deliberately ORTHOGONAL to role:/permission: — Laravel stacks middleware as
 * an AND, so a route already gated `role:SUPER_ADMIN,COMPANY_ADMIN` simply
 * gets a second `feature:enforcement` alongside it; effective access becomes
 * the intersection of "has the role" AND "plan carries the feature" without
 * touching the RBAC classes (EnsureRole/EnsurePermission) at all.
 *
 * Multiple feature keys are ANDed (`feature:enforcement,live_view` requires
 * both). Fails soft on any throw inside the licence machinery — same rule as
 * EnsureLicensed — so a bug here can never take a paying client's console
 * down; a real "plan does not include this" verdict still blocks normally.
 *
 * This gates ROUTES only. The agent-facing api/enforcer/policy endpoint must
 * never simply refuse (see its own route comment in routes/api.php) — that
 * one is gated at the DATA level inside EnforcerSyncController::policy()
 * instead of by this middleware.
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        try {
            $license = InstallationLicense::governing($request->user()?->company);

            foreach ($features as $feature) {
                if (! $license->hasFeature($feature)) {
                    return response()->json([
                        'error' => [
                            'code' => 'PLAN_FEATURE_REQUIRED',
                            'feature' => $feature,
                            'message' => "Your current plan does not include \"{$feature}\". Contact Ametecs to upgrade.",
                        ],
                    ], 403);
                }
            }
        } catch (\Throwable $e) {
            return $next($request);
        }

        return $next($request);
    }
}
