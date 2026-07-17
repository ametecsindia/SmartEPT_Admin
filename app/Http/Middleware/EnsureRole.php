<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `->middleware('role:MANAGER,COMPANY_ADMIN')`.
 * Super Admin passes every role check.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401);
        }

        if ($user->isSuperAdmin() || $user->hasRole(...$roles)) {
            return $next($request);
        }

        // R4 item 5: a custom role created in the console inherits route access
        // from the system role it is based on; the permission matrix then narrows
        // which modules its users actually see.
        $base = $user->role?->base_slug;
        if ($base && in_array($base, $roles, true)) {
            return $next($request);
        }

        return response()->json([
            'error' => ['code' => 'FORBIDDEN', 'message' => 'Your role does not permit this action.'],
        ], 403);
    }
}
