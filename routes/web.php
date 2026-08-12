<?php

use Illuminate\Support\Facades\Route;

// SmartEPT admin console (server-rendered shell + JS calling the JSON API).
Route::redirect('/', '/admin');
Route::view('/admin', 'admin');

// Branded-path API passthrough (Ejaz, 12-Aug-2026): SmartEPT-Cloud clients are
// given ONE address — their branded URL (admin.smartept.com/<slug>) — and agents
// configured with it must work out of the box, whatever the installed build.
// Anything under /<slug>/api/… is permanently redirected (308 preserves the HTTP
// method and body, so POST logins survive) to the real /api/…, which every HTTP
// client follows automatically. No CSRF/session middleware: this is a stateless
// bounce for machine clients, not a browser form.
Route::any('/{tenant}/api/{apiPath}', function (string $tenant, string $apiPath) {
    $qs = request()->getQueryString();

    return redirect('/api/' . $apiPath . ($qs ? '?' . $qs : ''), 308);
})->where(['tenant' => '[A-Za-z0-9][A-Za-z0-9-]*', 'apiPath' => '.+'])
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    ]);

// Per-client branded console (slug URLs): admin.smartept.com/<slug> serves the SAME
// single-page console. Data isolation is by company_id; the slug drives the branded,
// tenant-locked login. Unknown or reserved slugs 404 so real paths never collide.
Route::get('/{tenant}', function (string $tenant) {
    $reserved = [
        'admin', 'api', 'login', 'logout', 'sso', 'storage', 'assets', 'build',
        'vendor', 'css', 'js', 'img', 'images', 'fonts', 'up', 'favicon.ico', 'robots.txt',
    ];
    abort_if(in_array(strtolower($tenant), $reserved, true), 404);
    abort_unless(preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $tenant), 404);
    abort_unless(\App\Models\Company::where('slug', $tenant)->exists(), 404);

    return view('admin');
})->where('tenant', '[A-Za-z0-9._-]+');
