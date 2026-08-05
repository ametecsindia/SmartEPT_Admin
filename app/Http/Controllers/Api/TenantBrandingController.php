<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

/**
 * Public branding for the per-client login page (admin.smartept.com/<slug>).
 * Returns only the company's display name and logo so the login screen can be
 * branded before anyone signs in. No authentication, no sensitive data.
 */
class TenantBrandingController extends Controller
{
    /** GET /api/tenant-branding/{slug} */
    public function show(string $slug): JsonResponse
    {
        $company = Company::where('slug', $slug)->first();
        abort_unless($company, 404, 'Unknown workspace.');

        return response()->json([
            'slug'     => $company->slug,
            'name'     => $company->name,
            // logo_url is null unless the company has a custom logo configured.
            'logo_url' => $company->logo_url ?: null,
        ]);
    }
}
