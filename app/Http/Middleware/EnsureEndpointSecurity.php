<?php

namespace App\Http\Middleware;

use App\Services\EndpointSecurity\Entitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint Security plan gate (Oct-2026). Used by class name in routes/api.php —
 * no alias registration, so bootstrap/app.php is untouched.
 *
 *   ->middleware(EnsureEndpointSecurity::class)               needs 'monitoring'
 *   ->middleware(EnsureEndpointSecurity::class.':full_scan')  needs a Commander capability
 *
 * Stacks with role:/permission: (AND). Fails CLOSED — Entitlement answers 'none' on error.
 */
class EnsureEndpointSecurity
{
    public function handle(Request $request, Closure $next, string $capability = 'monitoring'): Response
    {
        if (! Entitlement::can($request->user()?->company, $capability)) {
            return self::denied($capability);
        }

        return $next($request);
    }

    public static function denied(string $capability = 'monitoring'): Response
    {
        $commander = ! in_array($capability, (array) config('endpoint_security.capabilities.basic', []), true);

        return response()->json(['error' => [
            'code' => 'FEATURE_NOT_AVAILABLE',
            'message' => $commander
                ? 'This Endpoint Security action is available in SmartEPT Commander.'
                : 'Endpoint Security is available in SmartEPT Enforcer and Commander.',
        ]], 403);
    }
}
