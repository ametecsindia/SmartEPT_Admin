<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDevice;
use App\Models\InstallationLicense;
use App\Services\LicenseClient;
use App\Services\LicenseFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * R2-1: admin Licence screen API — view status, set/replace the key,
 * force a re-validation against SmartEPT Central.
 *
 * Per-tenant licensing (12-Aug-2026): a COMPANY_ADMIN of a cloud tenant
 * (AMETECS_SAAS) sees and manages THEIR OWN licence row. The Super Admin
 * (host/operator) keeps the install-level licence here; per-tenant licences
 * live on the Tenants screen. The offline license.lic file is node-locked to
 * the server, so it stays an install-level concept only.
 */
class LicenseController extends Controller
{
    public function __construct(private LicenseClient $client, private LicenseFile $file)
    {
    }

    /** The licence row this user's Licence screen operates on. */
    private function rowFor(Request $request): InstallationLicense
    {
        $user = $request->user();

        if ($user && ! $user->isSuperAdmin()) {
            return InstallationLicense::governing($user->company);
        }

        return InstallationLicense::current();
    }

    /** GET /api/license */
    public function show(Request $request): JsonResponse
    {
        $license = $this->rowFor($request);

        // Offline-first (install-level only): a valid license.lic is the source
        // of truth and needs no network. Tenant rows always speak to Central.
        if ($license->company_id === null) {
            $license = $this->file->apply();
        }

        return $this->payload($license);
    }

    /** POST /api/license/import — upload/paste a signed license.lic (offline, node-locked). */
    public function import(Request $request): JsonResponse
    {
        // The .lic file is locked to this server's fingerprint — it can only ever
        // be the install-level licence, never one cloud tenant's.
        abort_if($this->rowFor($request)->company_id !== null, 403,
            'Offline licence files apply to self-hosted servers. Your cloud licence is managed by SmartEPT Central — use "Save & validate" with your key, or contact Ametecs.');

        $contents = '';
        if ($request->hasFile('file')) {
            $contents = (string) file_get_contents($request->file('file')->getRealPath());
        } else {
            $contents = (string) $request->input('token', '');
        }
        $contents = trim($contents);
        abort_if($contents === '', 422, 'Provide a licence file or paste its contents.');

        $license = $this->file->import($contents);
        $this->audit($request, 'LICENSE_FILE_IMPORT', InstallationLicense::class, $license->id, [
            'status' => $license->status,
        ]);

        return $this->payload($license);
    }

    /** POST /api/license — save (or replace) the key, then validate immediately. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
        ]);

        $license = $this->rowFor($request);
        $license->forceFill([
            'license_key' => trim($data['key']),
            'status' => 'unconfigured',
            'bundle' => null,
            'last_error' => null,
            'unreachable_since' => null,
            // Anchors the 7-day unverified window. Written ONLY where a key is
            // actually entered, so a failed nightly phone-home cannot reopen it.
            'key_saved_at' => now(),
        ])->save();

        $license = $this->client->validate($license);

        $this->audit($request, 'LICENSE_KEY_SET', InstallationLicense::class, $license->id, [
            'status' => $license->status,
            'company_id' => $license->company_id,
        ]);

        return $this->payload($license);
    }

    /** POST /api/license/validate — re-check the licence file (offline); phone home only if needed. */
    public function revalidate(Request $request): JsonResponse
    {
        $license = $this->rowFor($request);

        if ($license->company_id === null) {
            $license = $this->file->apply();
        }
        if ($license->status !== 'active' || $license->company_id !== null) {
            // validate() also handles the no-Central-URL case by recording a
            // clear last_error, so let it run either way (Ejaz, 12-Aug-2026).
            $license = $this->client->validate($license);
        }

        $this->audit($request, 'LICENSE_VALIDATED', InstallationLicense::class, $license->id, [
            'status' => $license->status,
            'company_id' => $license->company_id,
        ]);

        return $this->payload($license);
    }

    private function payload(InstallationLicense $license): JsonResponse
    {
        // Seats used: a tenant row counts ITS devices; the install row counts the box.
        $devices = EmployeeDevice::withoutGlobalScopes()
            ->when($license->company_id, fn ($q) => $q->where('company_id', $license->company_id))
            ->count();

        return response()->json([
            'configured' => $license->configured(),
            // Which licence this screen is showing: one tenant's own, or the install's.
            'scope' => $license->company_id ? 'company' : 'installation',
            'scope_company' => $license->company_id ? $license->company?->name : null,
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
            'devices_registered' => $devices,
            // Finding 1.3/1.4: what the seats are actually being spent on, and
            // whether the client is already over what they bought.
            'seats' => app(\App\Services\LicenceSeats::class)->counts($license->company_id),
            'expires_at' => optional($license->expiresAt())->toDateString(),
            'grace_days' => $license->graceDays(),
            'last_checked_at' => optional($license->last_checked_at)->toDateTimeString(),
            'last_error' => $license->last_error,
            'central_url' => $this->client->baseUrl(),
            // EPT-29: offline node-locked licence file (install-level only).
            'source' => $license->bundle['source'] ?? ($license->configured() ? 'central' : null),
            'machine_fingerprint' => $this->file->machineFingerprint(),
            'has_license_file' => $license->company_id === null && is_readable($this->file->path()),
            // 7-day no-key evaluation window (Ejaz's rule: then block everything).
            'evaluation_ends_at' => $license->configured() ? null : $license->evaluationEndsAt()->toDateString(),
            'evaluation_days_left' => $license->configured() ? null : $license->evaluationDaysLeft(),
        ]);
    }
}
