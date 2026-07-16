<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDevice;
use App\Models\InstallationLicense;
use App\Services\LicenseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * R2-1: admin Licence screen API — view status, set/replace the key,
 * force a re-validation against SmartEPT Central.
 */
class LicenseController extends Controller
{
    public function __construct(private LicenseClient $client)
    {
    }

    /** GET /api/license */
    public function show(): JsonResponse
    {
        return $this->payload(InstallationLicense::current());
    }

    /** POST /api/license — save (or replace) the key, then validate immediately. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
        ]);

        $license = InstallationLicense::current();
        $license->forceFill([
            'license_key' => trim($data['key']),
            'status' => 'unconfigured',
            'bundle' => null,
            'last_error' => null,
            'unreachable_since' => null,
        ])->save();

        $license = $this->client->validate($license);

        $this->audit($request, 'LICENSE_KEY_SET', InstallationLicense::class, $license->id, [
            'status' => $license->status,
        ]);

        return $this->payload($license);
    }

    /** POST /api/license/validate — force a phone-home check now. */
    public function revalidate(Request $request): JsonResponse
    {
        $license = $this->client->validate(InstallationLicense::current());

        $this->audit($request, 'LICENSE_VALIDATED', InstallationLicense::class, $license->id, [
            'status' => $license->status,
        ]);

        return $this->payload($license);
    }

    private function payload(InstallationLicense $license): JsonResponse
    {
        return response()->json([
            'configured' => $license->configured(),
            'key_masked' => $license->configured()
                ? substr($license->license_key, 0, 9) . '••••-••••-' . substr($license->license_key, -4)
                : null,
            'status' => $license->status,
            'operational' => $license->operational(),
            'within_grace' => $license->withinGrace(),
            'plan' => $license->planCode(),
            'company' => $license->companyName(),
            'kind' => $license->bundle['kind'] ?? null,
            'deployment' => $license->bundle['deployment'] ?? null,
            'features' => $license->bundle['features'] ?? [],
            'device_limit' => $license->deviceLimit(),
            'devices_registered' => EmployeeDevice::count(),
            'expires_at' => optional($license->expiresAt())->toDateString(),
            'grace_days' => $license->graceDays(),
            'last_checked_at' => optional($license->last_checked_at)->toDateTimeString(),
            'last_error' => $license->last_error,
            'central_url' => $this->client->baseUrl(),
            // 7-day no-key evaluation window (Ejaz's rule: then block everything).
            'evaluation_ends_at' => $license->configured() ? null : $license->evaluationEndsAt()->toDateString(),
            'evaluation_days_left' => $license->configured() ? null : $license->evaluationDaysLeft(),
        ]);
    }
}
