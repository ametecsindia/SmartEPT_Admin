<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cloud tenant provisioning (Ejaz 17-Jul) — the server-to-server door SmartEPT
 * Central calls when a SmartEPT-Managed-Cloud order is provisioned. Creates (or
 * re-uses) the tenant's Company and its COMPANY_ADMIN login, then hands back the
 * console URL. Authenticated by a shared secret, NOT a user token — this runs
 * before any user exists. Idempotent: keyed on external_tenant_id + admin email,
 * so a retry or a re-provision returns the same records without duplicating.
 */
class ProvisioningController extends Controller
{
    /** POST /api/provisioning/tenant  (header: X-Provision-Secret) */
    public function store(Request $request): JsonResponse
    {
        $secret = (string) config('services.provision.secret');
        $given = (string) $request->header('X-Provision-Secret', '');
        if ($secret === '' || ! hash_equals($secret, $given)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Bad provisioning secret.']], 401);
        }

        $data = $request->validate([
            'external_tenant_id' => ['required', 'string', 'max:64'],
            'company_name'       => ['required', 'string', 'max:191'],
            'admin_email'        => ['required', 'email', 'max:191'],
            'admin_name'         => ['nullable', 'string', 'max:191'],
            'timezone'           => ['nullable', 'string', 'max:64'],
            'device_limit'       => ['nullable', 'integer', 'min:1'],
            'retention_days'     => ['nullable', 'integer', 'min:1'],
        ]);

        $role = Role::where('slug', 'COMPANY_ADMIN')->whereNull('company_id')->first()
            ?? Role::where('slug', 'COMPANY_ADMIN')->first();
        if (! $role) {
            return response()->json(['error' => ['code' => 'NO_ROLE', 'message' => 'COMPANY_ADMIN role not seeded.']], 500);
        }

        [$company, $user, $tempPassword, $created] = DB::transaction(function () use ($data, $role) {
            $company = Company::firstOrNew(['external_tenant_id' => $data['external_tenant_id']]);
            $companyCreated = ! $company->exists;
            if ($companyCreated) {
                $company->name = $data['company_name'];
                $company->code = $this->uniqueCode($data['company_name']);
                $company->deployment_model = 'AMETECS_SAAS';
            }
            $company->timezone = $data['timezone'] ?: ($company->timezone ?: 'Asia/Kolkata');
            if (! empty($data['retention_days'])) {
                $company->data_retention_days = $data['retention_days'];
            }
            $company->status = 'ACTIVE';
            $company->save();

            // The admin login. If the email already exists we adopt it (attach to
            // this company + role) rather than error — keeps re-provision safe.
            $user = User::firstOrNew(['email' => $data['admin_email']]);
            $userCreated = ! $user->exists;
            $tempPassword = null;
            if ($userCreated) {
                $tempPassword = Str::password(14, symbols: false);
                $user->password = Hash::make($tempPassword);
                $user->must_change_password = true;
            }
            $user->name = $data['admin_name'] ?: ($user->name ?: 'Company Admin');
            $user->company_id = $company->id;
            $user->role_id = $role->id;
            $user->status = 'ACTIVE';
            $user->save();

            return [$company, $user, $tempPassword, $companyCreated];
        });

        return response()->json([
            'company_id'         => $company->id,
            'external_tenant_id' => $company->external_tenant_id,
            'admin_email'        => $user->email,
            'admin_name'         => $user->name,
            'temp_password'      => $tempPassword, // null when the admin already existed
            'console_url'        => rtrim(config('app.url'), '/') . '/admin',
            'created'            => $created,
        ]);
    }

    /** company code from the name, uniqued with a short suffix if taken. */
    private function uniqueCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '')) ?: 'CO';
        $base = Str::substr($base, 0, 10);
        $code = $base;
        while (Company::where('code', $code)->exists()) {
            $code = $base . Str::upper(Str::random(3));
        }
        return $code;
    }
}
