<?php

namespace App\Http\Middleware;

use App\Support\CardAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fine-grained guard: `->middleware('permission:screenshot.view')`.
 * Super Admin implicitly holds every permission.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401);
        }

        // 25-Sep-2026: a route the role matrix governs is decided by its View/Edit tick.
        $card = CardAccess::decide($request);
        if ($card !== null) {
            return $card ? $next($request) : EnsureRole::denied();
        }

        if (! $user->hasPermission($permission)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => "Missing permission: {$permission}."],
            ], 403);
        }

        return $next($request);
    }
}
