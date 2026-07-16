<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces HTTPS on API traffic when config('smartept.force_https') is true.
 * Kept false on Laragon local (http://smartept.test); enable in production.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('smartept.force_https') && ! $request->secure()) {
            return response()->json([
                'error' => ['code' => 'HTTPS_REQUIRED', 'message' => 'HTTPS is required for this endpoint.'],
            ], 403);
        }

        return $next($request);
    }
}
