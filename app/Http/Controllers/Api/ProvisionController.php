<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cloud provisioning (EPT-27) — the receiving end of SmartEPT Central's
 * ProductProvisioner. A secret-signed call stands up (or re-uses) a Company +
 * COMPANY_ADMIN login for a cloud tenant and returns the console URL. Idempotent
 * by external_tenant_id, so a retry never duplicates. NO auth guard — the shared
 * X-Provision-Secret (must equal Central's PRODUCT_PROVISION_SECRET) is the gate.
 */
class ProvisionController extends Controller
{
    /** POST /api/provision */
    public function provision(Request $request): JsonResponse
    {
        $secret = (string) config('services.provision.secret');
        abort_if($secret === '', 503, 'Provisioning is not configured on this server.');
        abort_unless(hash_equals($secret, (string) $request->header('X-Provision-Secret')), 401, 'Invalid provisioning secret.');

        $data = $request->validate([
            'external_tenant_id' => ['required', 'string', 'max:64'],
            'company_name'       => ['required', 'string', 'max:255'],
            'admin_email'        => ['required', 'email', 'max:255'],
            'admin_name'         => ['nullable', 'string', 'max:255'],
            'timezone'           => ['nullable', 'string', 'max:64'],
            'device_limit'       => ['nullable', 'integer', 'min:1'],
            'slug'               => ['nullable', 'string', 'max:40'],
            // Central owns the storage allocation: seats x per-user free storage (+ any
            // purchased top-up), in MB. Pushed on create and re-pushed on seat/plan/
            // storage changes. 0/absent = leave as-is; explicit 0 elsewhere = unlimited.
            'storage_quota_mb'   => ['nullable', 'integer', 'min:0'],
            // Central announces its own URL so licence validation configures itself —
            // no .env edit on the product, ever (Ejaz, 12-Aug-2026).
            'central_url'        => ['nullable', 'url', 'max:190'],
            // Per-tenant licensing (12-Aug-2026): Central hands the tenant's licence
            // key over at provisioning, so the tenant is licensed the moment their
            // console exists — no key ever pasted by hand on a cloud tenant.
            'licence_key'        => ['nullable', 'string', 'max:64'],
            // CONSOLE MAIL AUTOPILOT (13-Aug-2026): Central hands over its SMTP
            // so a fresh console can email OTPs/credentials with zero manual setup.
            'mail'               => ['nullable', 'array'],
        ]);

        if (! empty($data['central_url'])) {
            \App\Models\Setting::put('license_central_url', rtrim($data['central_url'], '/'));
        }

        // Adopt Central's SMTP as this console's global relay — ONLY when no
        // global SMTP is configured here yet, so a super's own Ops → Email/SMTP
        // entry is never overwritten. Password stored encrypted, same as the
        // Ops screen does (MailService decrypts either form).
        if (! empty($data['mail']['host']) && ! \App\Models\Setting::get('mail_host')) {
            $m = $data['mail'];
            \App\Models\Setting::put('mail_host', (string) $m['host']);
            \App\Models\Setting::put('mail_port', (string) ($m['port'] ?? 587));
            \App\Models\Setting::put('mail_username', (string) ($m['username'] ?? ''));
            \App\Models\Setting::put('mail_password', ($m['password'] ?? '') !== ''
                ? \Illuminate\Support\Facades\Crypt::encryptString((string) $m['password']) : '');
            \App\Models\Setting::put('mail_encryption', (string) ($m['encryption'] ?? 'tls'));
            \App\Models\Setting::put('mail_from_address', (string) ($m['from_address'] ?? ''));
            \App\Models\Setting::put('mail_from_name', (string) ($m['from_name'] ?? ''));
            Log::info('Global SMTP inherited from Central at provisioning');
        }

        // 1) Company — idempotent on external_tenant_id.
        $company = Company::where('external_tenant_id', $data['external_tenant_id'])->first();
        if (! $company) {
            $company = Company::create([
                'name'               => $data['company_name'],
                'code'               => $this->uniqueCode($data['company_name'], $data['external_tenant_id']),
                'slug'               => $this->uniqueSlug($data['slug'] ?? $data['company_name']),
                'external_tenant_id' => $data['external_tenant_id'],
                // ?? first: a nullable validated field is ABSENT from $data when not
                // sent at all — bare $data['timezone'] crashed with "Undefined array
                // key" (found by PerTenantLicenseTest, 12-Aug).
                'timezone'           => ($data['timezone'] ?? null) ?: 'Asia/Kolkata',
                'deployment_model'   => 'AMETECS_SAAS',
                'status'             => 'ACTIVE',
                'storage_quota_mb'   => array_key_exists('storage_quota_mb', $data) ? ($data['storage_quota_mb'] ?: null) : null,
            ]);
        } else {
            // Existing company — keep its slug in sync with Central (idempotent).
            $want = ! empty($data['slug']) ? $data['slug'] : ($company->slug ?: $company->name);
            $newSlug = $this->uniqueSlug($want, $company->id);
            if ($company->slug !== $newSlug) {
                $company->forceFill(['slug' => $newSlug])->save();
            }
            // Re-push storage allocation from Central (seat change / storage top-up).
            if (array_key_exists('storage_quota_mb', $data)) {
                $company->forceFill(['storage_quota_mb' => $data['storage_quota_mb'] ?: null])->save();
            }
        }

        // 2) COMPANY_ADMIN login — reuse by email, else create with a temp password.
        $tempPassword = null;
        $user = User::where('email', $data['admin_email'])->first();
        if (! $user) {
            $role = Role::where('slug', 'COMPANY_ADMIN')->first();
            abort_unless($role, 500, 'COMPANY_ADMIN role is missing — run the role seeder on this server.');

            $tempPassword = Str::password(12);
            $user = User::create([
                'name'                 => ($data['admin_name'] ?? null) ?: ($data['company_name'] . ' Admin'),
                'email'                => $data['admin_email'],
                'password'             => $tempPassword,          // hashed by the model cast
                'company_id'           => $company->id,
                'role_id'              => $role->id,
                'status'               => 'ACTIVE',
                'must_change_password' => true,
            ]);
        }

        // 3) The tenant's OWN licence row — created/updated with the key Central
        //    sent, then validated immediately (best-effort; the daily revalidate
        //    and the Licence screen's "Validate now" both recover a miss).
        if (! empty($data['licence_key'])) {
            $licence = \App\Models\InstallationLicense::forCompany($company->id);
            if ($licence->license_key !== trim($data['licence_key'])) {
                $licence->forceFill([
                    'license_key' => trim($data['licence_key']),
                    'status' => 'unconfigured',
                    'bundle' => null,
                    'last_error' => null,
                    'unreachable_since' => null,
                ])->save();
            }
            try {
                app(\App\Services\LicenseClient::class)->validate($licence);
            } catch (\Throwable $e) {
                Log::warning('Provision: licence validate deferred', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('Cloud tenant provisioned', [
            'external_tenant_id' => $data['external_tenant_id'],
            'company_id'         => $company->id,
            'new_admin'          => (bool) $tempPassword,
            'licence_key_set'    => ! empty($data['licence_key']),
        ]);

        return response()->json([
            'ok'            => true,
            'company_id'    => $company->id,
            'slug'          => $company->slug,
            // Branded per-client console URL (falls back to /admin if no slug).
            'console_url'   => url($company->slug ? '/' . $company->slug : '/admin'),
            'admin_email'   => $user->email,
            'temp_password' => $tempPassword,   // null when the admin already existed
        ], 201);
    }

    /**
     * POST /api/provision/status  { external_tenant_id, status: ACTIVE|SUSPENDED }
     * Central pushes a tenant's suspend/enable here (secret-signed) so the hosted console
     * blocks or restores that company's people immediately.
     */
    public function setStatus(Request $request): JsonResponse
    {
        $secret = (string) config('services.provision.secret');
        abort_if($secret === '', 503, 'Provisioning is not configured on this server.');
        abort_unless(hash_equals($secret, (string) $request->header('X-Provision-Secret')), 401, 'Invalid provisioning secret.');

        $data = $request->validate([
            'external_tenant_id' => ['required', 'string', 'max:64'],
            'status'             => ['required', 'in:ACTIVE,SUSPENDED'],
        ]);

        $company = Company::where('external_tenant_id', $data['external_tenant_id'])->first();
        abort_unless($company, 404, 'Unknown tenant.');
        $company->update(['status' => $data['status']]);

        Log::info('Tenant status set from Central', [
            'external_tenant_id' => $data['external_tenant_id'],
            'company_id'         => $company->id,
            'status'             => $data['status'],
        ]);

        return response()->json(['ok' => true, 'company_id' => $company->id, 'status' => $company->status]);
    }

    /** A short, unique, human-ish company code. */
    private function uniqueCode(string $name, string $ext): string
    {
        $base = strtoupper(Str::slug(Str::of($name)->limit(8, ''), '')) ?: 'TEN';
        $tail = substr(preg_replace('/\D/', '', $ext) ?: '', -4);
        $code = substr($base . $tail, 0, 60);
        $i = 1;
        while (Company::where('code', $code)->exists()) {
            $code = substr($base, 0, 56) . '-' . $i++;
        }

        return $code;
    }

    /**
     * A clean, unique, lowercase URL slug for the branded console path
     * (admin.smartept.com/<slug>). Derived from Central's suggestion or the
     * company name; a numeric suffix is added if the slug is already taken.
     */
    private function uniqueSlug(string $seed, ?int $ignoreId = null): string
    {
        $base = trim(Str::slug($seed, '-'), '-');
        // Keep it path-safe and within the 40-char column; ensure it starts alnum.
        $base = substr($base, 0, 38) ?: 'client';
        if (! preg_match('/^[a-z0-9]/', $base)) {
            $base = 'c' . $base;
        }

        $slug = $base;
        $i = 1;
        while (Company::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = substr($base, 0, 34) . '-' . $i++;
        }

        return $slug;
    }
}
