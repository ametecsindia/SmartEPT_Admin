<?php

use Illuminate\Support\Facades\Route;

// SmartEPT admin console (server-rendered shell + JS calling the JSON API).
Route::redirect('/', '/admin');
Route::view('/admin', 'admin');

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
