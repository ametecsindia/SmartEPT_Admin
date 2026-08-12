<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InstallationLicense;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * R2-1: phone-home client for SmartEPT Central licensing.
 *
 * HARD WALL: only licence metadata travels — key, fingerprint, device seat
 * UIDs/hostnames. Never operational data (screenshots, activity, camera).
 *
 * Availability first: if Central is unreachable the last cached verdict stands
 * (the entitlement bundle carries expiry + grace, enforced locally).
 *
 * Per-tenant licensing (12-Aug-2026): on the shared cloud install each
 * AMETECS_SAAS company phones home with its OWN key (its own licence row);
 * everything else still uses the single install-level row.
 */
class LicenseClient
{
    /**
     * SmartEPT Central's address, resolved automatically (Ejaz, 12-Aug-2026 —
     * clients must never edit .env for this):
     *   1. SMARTEPT_LICENSE_URL in .env (explicit override, e.g. Laragon dev),
     *   2. the licence_central_url Setting pushed by Central at provisioning,
     *   3. https://smartept.com — Central's permanent home, the default.
     */
    public function baseUrl(): ?string
    {
        $url = rtrim((string) config('smartept.license_url'), '/');

        if ($url === '') {
            try {
                $url = rtrim((string) \App\Models\Setting::get('license_central_url'), '/');
            } catch (\Throwable $e) {
                $url = ''; // settings table unavailable (mid-migration) — fall through
            }
        }

        return $url !== '' ? $url : 'https://smartept.com';
    }

    /**
     * The HTTP client for every Central phone-home. When SMARTEPT_LICENSE_VERIFY=false
     * it skips TLS certificate verification — the on-prem escape hatch for a local PC
     * whose PHP has no CA bundle (the usual "cURL error 60" on Windows/IIS).
     */
    private function http()
    {
        $req = Http::timeout(10)->acceptJson();
        if (! config('smartept.license_verify', true)) {
            $req = $req->withoutVerifying();
        }

        return $req;
    }

    /** Stable anonymous identity of THIS server (no hostname leaves the box un-hashed). */
    public function fingerprint(): string
    {
        return hash('sha256', config('app.key') . '|' . gethostname());
    }

    /** The licence row governing a company id (device flows carry the id, not the model). */
    public function licenseForCompanyId(?int $companyId): InstallationLicense
    {
        $company = $companyId ? Company::find($companyId) : null;

        return InstallationLicense::governing($company);
    }

    /**
     * EPT-27: evidence storage reported to Central on each phone-home, in GB.
     * A tenant's own licence row reports ONLY that tenant's files; the
     * install-level row reports the whole box. Always unscoped — the daily
     * revalidate can fire under any authenticated user's global scope.
     */
    private function currentStorageGb(?int $companyId = null): float
    {
        try {
            return round((int) \App\Models\StorageFile::withoutGlobalScopes()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->sum('size_bytes') / (1024 ** 3), 3);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /** Validate the stored key against Central and cache the entitlement bundle. */
    public function validate(?InstallationLicense $license = null): InstallationLicense
    {
        $license ??= InstallationLicense::current();

        if (! $license->configured()) {
            return $license;
        }

        // A key with nowhere to phone home is a server-config problem — say so
        // instead of leaving the misleading bare "unconfigured" (Ejaz, 12-Aug-2026).
        if (! $this->baseUrl()) {
            $license->forceFill([
                'last_error' => 'Central URL not configured on this server — set SMARTEPT_LICENSE_URL in .env (e.g. https://smartept.com), run php artisan config:clear, then validate again.',
            ])->save();

            return $license;
        }

        try {
            $resp = $this->http()->post($this->baseUrl() . '/api/v1/license/validate', [
                'key' => $license->license_key,
                'fingerprint' => $this->fingerprint(),
                'storage_gb' => $this->currentStorageGb($license->company_id),
            ]);
        } catch (\Throwable $e) {
            $license->forceFill([
                'unreachable_since' => $license->unreachable_since ?: now(),
                'last_error' => 'Central unreachable: ' . mb_substr($e->getMessage(), 0, 400),
                'last_checked_at' => now(),
            ])->save();
            Log::warning('License validation: Central unreachable', ['error' => $e->getMessage()]);

            return $license;
        }

        $json = $resp->json() ?? [];

        if ($resp->successful() && ($json['ok'] ?? false)) {
            $license->forceFill([
                'status' => 'active',
                'bundle' => $json['bundle'] ?? $license->bundle,
                'last_checked_at' => now(),
                'unreachable_since' => null,
                'last_error' => null,
            ])->save();
            // EPT-27: honour Central's storage governance — pause new screenshots at 100%.
            // Per-tenant rows keep governance per company; the install row keeps the
            // original install-wide switch (client-hosted, one company anyway).
            try {
                $st = $json['storage'] ?? null;
                $suffix = $license->company_id ? ':' . $license->company_id : '';
                \App\Models\Setting::put('storage_paused' . $suffix, ($st && ! empty($st['pause_screenshots'])) ? '1' : '0');
                if (is_array($st)) {
                    \App\Models\Setting::put('storage_status' . $suffix, json_encode($st));
                }
            } catch (\Throwable $e) {
                // never let governance break the phone-home
            }
        } else {
            $reason = (string) ($json['reason'] ?? ('http_' . $resp->status()));
            // Central reasons: unknown_key | licence_expired | licence_suspended | licence_revoked | server_mismatch
            $status = str_starts_with($reason, 'licence_') ? substr($reason, 8) : $reason;
            $license->forceFill([
                'status' => $status,
                'last_checked_at' => now(),
                'unreachable_since' => null,
                'last_error' => $reason,
            ])->save();
        }

        return $license;
    }

    /**
     * Revalidate at most once a day, guarded by a short cache lock so a burst of
     * agent traffic never hammers Central (or stalls on it when it is down).
     * The lock is per licence row — one tenant's revalidate never starves another's.
     */
    public function ensureFresh(InstallationLicense $license): InstallationLicense
    {
        if (! $license->configured()) {
            return $license;
        }

        $stale = $license->last_checked_at === null || $license->last_checked_at->lt(now()->subDay());

        if ($stale && Cache::add('license:revalidate-lock:' . $license->id, 1, 600)) {
            return $this->validate($license);
        }

        return $license;
    }

    /**
     * Claim a device seat on Central against the licence governing the device's
     * company. Returns ['ok' => bool, 'reason' => ?string].
     * Unreachable Central → ok (offline-tolerant; the daily validate reconciles).
     */
    public function activateDevice(string $deviceUid, ?string $hostname = null, ?int $companyId = null): array
    {
        $license = $this->licenseForCompanyId($companyId);

        if (! $license->configured() || ! $this->baseUrl()) {
            return ['ok' => true, 'reason' => null];
        }

        try {
            $resp = $this->http()->post($this->baseUrl() . '/api/v1/license/device/activate', [
                'key' => $license->license_key,
                'device_uid' => $deviceUid,
                'hostname' => $hostname,
            ]);
        } catch (\Throwable $e) {
            Log::warning('License device activate: Central unreachable', ['device_uid' => $deviceUid]);

            return ['ok' => true, 'reason' => 'central_unreachable'];
        }

        $json = $resp->json() ?? [];

        return ['ok' => (bool) ($json['ok'] ?? false), 'reason' => $json['reason'] ?? null];
    }

    /** Release a device seat on Central (offboarding / unbind). Best-effort. */
    public function deactivateDevice(string $deviceUid, ?int $companyId = null): bool
    {
        $license = $this->licenseForCompanyId($companyId);

        if (! $license->configured() || ! $this->baseUrl()) {
            return true;
        }

        try {
            $resp = $this->http()->post($this->baseUrl() . '/api/v1/license/device/deactivate', [
                'key' => $license->license_key,
                'device_uid' => $deviceUid,
            ]);

            return (bool) ($resp->json()['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('License device deactivate: Central unreachable', ['device_uid' => $deviceUid]);

            return false;
        }
    }
}
