<?php

namespace App\Services;

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
 */
class LicenseClient
{
    public function baseUrl(): ?string
    {
        $url = rtrim((string) config('smartept.license_url'), '/');

        return $url !== '' ? $url : null;
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

    /** Validate the stored key against Central and cache the entitlement bundle. */
    /** EPT-27: total evidence storage this installation is using, in GB (reported to Central on each phone-home). */
    private function currentStorageGb(): float
    {
        try {
            return round((int) \App\Models\StorageFile::sum('size_bytes') / (1024 ** 3), 3);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

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
                'storage_gb' => $this->currentStorageGb(),
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
            try {
                $st = $json['storage'] ?? null;
                \App\Models\Setting::put('storage_paused', ($st && ! empty($st['pause_screenshots'])) ? '1' : '0');
                if (is_array($st)) {
                    \App\Models\Setting::put('storage_status', json_encode($st));
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
     */
    public function ensureFresh(InstallationLicense $license): InstallationLicense
    {
        if (! $license->configured()) {
            return $license;
        }

        $stale = $license->last_checked_at === null || $license->last_checked_at->lt(now()->subDay());

        if ($stale && Cache::add('license:revalidate-lock', 1, 600)) {
            return $this->validate($license);
        }

        return $license;
    }

    /**
     * Claim a device seat on Central. Returns ['ok' => bool, 'reason' => ?string].
     * Unreachable Central → ok (offline-tolerant; the daily validate reconciles).
     */
    public function activateDevice(string $deviceUid, ?string $hostname = null): array
    {
        $license = InstallationLicense::current();

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
    public function deactivateDevice(string $deviceUid): bool
    {
        $license = InstallationLicense::current();

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
