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
        // 'strict' redirects (13-Aug-2026, Skill Dunya live http_405): live Central
        // announces itself as http:// (APP_URL), the server 301s to https, and
        // Guzzle's DEFAULT redirect mode converts the redirected POST into a GET —
        // which Central's POST-only /api/validate answers with 405. strict=true
        // keeps a POST a POST across redirects, so any http->https hop is harmless.
        $req = Http::timeout(10)->acceptJson()
            ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => true]]);
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

    /**
     * How many people/PCs this console actually has, for the daily report to
     * Central. Fail-soft: a counting error must never stop the phone-home.
     */
    private function usageCounts(?int $companyId = null): array
    {
        try {
            $c = app(LicenceSeats::class)->counts($companyId);

            return ['users' => $c['users'], 'employees' => $c['employees'], 'devices' => $c['devices']];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The only reasons Central is allowed to demote a licence with.
     *
     * A closed set on purpose. Anything outside it — a new reason string, a
     * proxy error page, a truncated body — is treated as "Central could not
     * answer", not as "this client is unlicensed". The cost of being wrong in
     * one direction is a client keeps working for another day; in the other it
     * is a paid client's console going dark.
     */
    private const DEMOTING_REASONS = [
        'unknown_key',
        'server_mismatch',
        'licence_expired',
        'licence_suspended',
        'licence_revoked',
        'licence_superseded',
    ];

    /**
     * Did Central actually deliver a verdict, or did it merely fail?
     *
     * THE REASON IS THE VERDICT, NOT THE HTTP STATUS. Central answers a genuine
     * rejection with **403** — see smartept-central
     * `app/Http/Controllers/Api/LicenseController.php:58`:
     *
     *     return response()->json($result, ($result['ok'] ?? false) ? 200 : 403);
     *
     * So requiring a 200 here would mean no licence could ever be demoted
     * again, which is a far worse bug than the one being fixed. What separates
     * a verdict from an outage is whether the body names a reason we recognise:
     * a 500 with an HTML error page, a proxy timeout or a body we cannot parse
     * name nothing, and say nothing about whether this client has paid.
     */
    private function isVerdict(array $json, string $reason): bool
    {
        if (! array_key_exists('reason', $json)) {
            return false;
        }

        return in_array($reason, self::DEMOTING_REASONS, true);
    }

    /**
     * Merge Central's bundle over ours WITHOUT losing how this licence arrived.
     *
     * `bundle['source']` is the only thing marking a licence as coming from a
     * signed .lic file, and fromFile() is what protects an on-premise client
     * from being demoted by Central. Central's bundle does not carry that key,
     * so replacing the bundle wholesale quietly converted a file licence into a
     * Central one — after which the very next unknown_key DID take the console
     * down, which is the defect the fromFile() guard was written to prevent.
     */
    private function mergeBundle(InstallationLicense $license, ?array $incoming): ?array
    {
        if (! is_array($incoming)) {
            return $license->bundle;
        }

        $existing = is_array($license->bundle) ? $license->bundle : [];
        if (isset($existing['source']) && ! isset($incoming['source'])) {
            $incoming['source'] = $existing['source'];
        }

        return $incoming;
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
                // Seat telemetry (Ejaz, 14-Aug-2026 — finding 1.3). Counts only,
                // no names: Central could previously only see PCs that called
                // device/activate, so a live 14-person client read as "0/25".
                // The HARD WALL still holds — this is licence metadata, nothing else.
            ] + $this->usageCounts($license->company_id));
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
                'bundle' => $this->mergeBundle($license, $json['bundle'] ?? null),
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

            // A .lic licence is verified locally against our public key and this
            // machine's fingerprint. Central not recognising the key means Central is
            // out of date, NOT that the client is unlicensed — so record the reason but
            // leave the verdict alone. Without this an explicit "Revalidate" click on an
            // on-premise install could take a paid client's console down.
            if ($license->fromFile()) {
                $license->forceFill([
                    'last_checked_at' => now(),
                    'unreachable_since' => null,
                    'last_error' => 'Central did not recognise this key (' . $reason
                        . ') — the signed licence file remains authoritative.',
                ])->save();

                return $license;
            }

            // Only an ANSWER from Central may demote a licence. A 500, a 502, a 405
            // from a misrouted request, or a body we do not understand are all
            // Central-side failures — they say nothing about whether this client is
            // paid up. Writing 'http_500' into status was silently fatal: nothing
            // matches it in InstallationLicense::operational(), so it fell through to
            // `default => false` and 403'd the entire console until Central came back.
            //
            // Same failure shape as the http_405 incident. Treat it as unreachable:
            // the last known verdict stands, and availability wins.
            if (! $this->isVerdict($json, $reason)) {
                $license->forceFill([
                    'unreachable_since' => $license->unreachable_since ?: now(),
                    'last_checked_at' => now(),
                    'last_error' => 'Central could not answer (' . $reason
                        . ') — the last known verdict stands.',
                ])->save();
                Log::warning('License validation: Central did not return a verdict', [
                    'status' => $resp->status(),
                    'reason' => $reason,
                ]);

                return $license;
            }

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
        // The developer toggle applies HERE too.
        //
        // It is documented as "turns licence enforcement off on OUR machines",
        // and EnsureLicensed honours it — but this path did not, so on a dev
        // install every PC that had already registered kept working while every
        // NEW one was refused by Central with "invalid_licence". The symptom is
        // an employee who cannot sign in at all on a second computer, which
        // reads as a broken agent rather than a licence that was supposed to be
        // switched off.
        //
        // Fails safe the same way DevLicenceKey does: the toggle is a keyed file
        // bound to this machine's fingerprint, so a client cannot create one.
        if (! DevLicenceKey::enforcementOn()) {
            return ['ok' => true, 'reason' => null];
        }

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
        // Symmetrical with activateDevice. Releasing a seat that was never taken
        // is a no-op, and calling Central about it on a dev install only
        // produces noise in the log.
        if (! DevLicenceKey::enforcementOn()) {
            return true;
        }

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
