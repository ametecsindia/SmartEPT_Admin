<?php

// CROSS-APP DB POISONING FIX (Ejaz, 13-Aug-2026): this console and SmartEPT
// Central share one Apache/PHP-FPM worker pool (Laragon local AND the live
// VPS). Laravel's env loader uses putenv(), which PERSISTS in a worker across
// requests — so a worker that just served the OTHER app keeps its DB_DATABASE
// and, because dotenv loading is immutable, this app then silently runs on the
// WRONG DATABASE (the July "agent/screenshots broken — wrong DB" incident, and
// Central's licence-validate 500 on 13-Aug, were both this). Disabling putenv
// makes every request read .env fresh — full isolation. Same line in both repos.
\Illuminate\Support\Env::disablePutenv();

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Named middleware used by SmartEPT routes.
        $middleware->alias([
            'role'       => \App\Http\Middleware\EnsureRole::class,
            'company.active' => \App\Http\Middleware\EnsureCompanyActive::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'consent'    => \App\Http\Middleware\EnsureConsent::class,
            'tracking-mode' => \App\Http\Middleware\EnforceTrackingMode::class,
            'licensed'   => \App\Http\Middleware\EnsureLicensed::class,
            'active-employee' => \App\Http\Middleware\EnsureActiveEmployment::class,
            // 26-Aug-2026: refuse tracking ingestion from a device whose session has ended.
            'live-session' => \App\Http\Middleware\EnsureLiveDeviceSession::class,
            'api-key'    => \App\Http\Middleware\ApiKeyAuth::class,
        ]);

        // Every API request is forced to HTTPS in production (config-gated for Laragon local).
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceHttps::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON 401 for unauthenticated API calls instead of redirecting to a login route.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
                ], 401);
            }
        });
    })->create();
