<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

/**
 * Hard cut-off for a suspended tenant (Ejaz 25-Jul). When Central suspends a company,
 * its Company.status becomes SUSPENDED; this rejects every authenticated request from
 * that company's console users at once — not just new logins — and lifts automatically
 * when Central re-enables them (no token reset / re-pairing needed). Super-admin
 * platform users (no company) and agents (no console user) are unaffected.
 */
class EnsureCompanyActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->company_id) {
            $company = $user->relationLoaded('company') ? $user->company : Company::find($user->company_id);
            if ($company && $company->status === 'SUSPENDED') {
                return response()->json([
                    'error' => [
                        'code'    => 'COMPANY_SUSPENDED',
                        'message' => 'Your organisation\'s access is suspended. Please contact Ametecs to restore it.',
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
