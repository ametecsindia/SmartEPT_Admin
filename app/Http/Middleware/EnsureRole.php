<?php

namespace App\Http\Middleware;

use App\Support\CardAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `->middleware('role:MANAGER,COMPANY_ADMIN')`.
 * Super Admin passes every role check.
 *
 * 25-Sep-2026: for console routes mapped in App\Support\CardAccess, the role's
 * View/Edit ticks decide first (grant AND deny); the role list below is only the
 * rule for routes the matrix does not govern.
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

        $card = CardAccess::decide($request);
        if ($card === true) {
            return $next($request);
        }
        if ($card === false) {
            return self::denied();
        }

        if ($user->isSuperAdmin() || $user->hasRole(...$roles)) {
            return $next($request);
        }

        // R4 item 5: a custom role created in the console inherits route access
        // from the system role it is based on, for routes the matrix does not govern.
        $base = $user->role?->base_slug;
        if ($base && in_array($base, $roles, true)) {
            return $next($request);
        }

        return self::denied();
    }

    public static function denied(): Response
    {
        return response()->json([
            'error' => ['code' => 'FORBIDDEN', 'message' => 'Your role does not permit this action.'],
        ], 403);
    }
}
