<?php

use Illuminate\Support\Facades\Route;

// SmartEPT admin console (server-rendered shell + JS calling the JSON API).
Route::redirect('/', '/admin');
Route::view('/admin', 'admin');

// Branded-path API (Ejaz, 13-Aug-2026): /<slug>/api/… is rewritten to /api/…
// in public/index.php BEFORE routing — the old 308 redirect here broke the
// Windows agent (its HTTP stack follows redirects as GET → 405 on POST routes,
// which the agent reported as "licence not active (http_405)").

// PRE-LOGIN licence activation (SmartPRS2 AS-DL pattern, 13-Aug-2026): on-prem
// client installs activate with their .lic BEFORE any sign-in. Cloud consoles
// show a "managed by Ametecs" notice instead (SMARTEPT_ONPREM unset).
Route::get('/activate', [\App\Http\Controllers\ActivateController::class, 'show']);
Route::post('/activate', [\App\Http\Controllers\ActivateController::class, 'store'])->middleware('throttle:10,1');

// Per-client branded console (slug URLs): admin.smartept.com/<slug> serves the SAME
// single-page console. Data isolation is by company_id; the slug drives the branded,
// tenant-locked login. Unknown or reserved slugs 404 so real paths never collide.
Route::get('/{tenant}', function (string $tenant) {
    $reserved = [
        'admin', 'api', 'login', 'logout', 'sso', 'storage', 'assets', 'build',
        'vendor', 'css', 'js', 'img', 'images', 'fonts', 'up', 'favicon.ico', 'robots.txt', 'activate',
    ];
    abort_if(in_array(strtolower($tenant), $reserved, true), 404);
    abort_unless(preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $tenant), 404);
    abort_unless(\App\Models\Company::where('slug', $tenant)->exists(), 404);

    return view('admin');
})->where('tenant', '[A-Za-z0-9._-]+');
