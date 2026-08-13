<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Branded-path API (Ejaz, 13-Aug-2026): agents are configured with the branded
// URL (admin.smartept.com/<slug>), so their calls arrive as /<slug>/api/…
// The old fix 308-redirected these to /api/… — but the Windows agent's HTTP
// stack follows redirects as GET, so every POST (auth/login, activation, sync)
// hit a POST-only route as GET and got 405 ("licence not active (http_405)").
// Serve them directly instead: strip the slug prefix BEFORE Laravel routes.
// Same method, same body, no redirect — works for every HTTP client.
if (preg_match('#^/[A-Za-z0-9][A-Za-z0-9-]*/api/#', $_SERVER['REQUEST_URI'] ?? '')) {
    $_SERVER['REQUEST_URI'] = preg_replace('#^/[^/]+#', '', $_SERVER['REQUEST_URI']);
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
