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
        ]);

        // 1) Company — idempotent on external_tenant_id.
        $company = Company::where('external_tenant_id', $data['external_tenant_id'])->first();
        if (! $company) {
            $company = Company::create([
                'name'               => $data['company_name'],
                'code'               => $this->uniqueCode($data['company_name'], $data['external_tenant_id']),
                'external_tenant_id' => $data['external_tenant_id'],
                'timezone'           => $data['timezone'] ?: 'Asia/Kolkata',
                'deployment_model'   => 'AMETECS_SAAS',
                'status'             => 'ACTIVE',
            ]);
        }

        // 2) COMPANY_ADMIN login — reuse by email, else create with a temp password.
        $tempPassword = null;
        $user = User::where('email', $data['admin_email'])->first();
        if (! $user) {
            $role = Role::where('slug', 'COMPANY_ADMIN')->first();
            abort_unless($role, 500, 'COMPANY_ADMIN role is missing — run the role seeder on this server.');

            $tempPassword = Str::password(12);
            $user = User::create([
                'name'                 => $data['admin_name'] ?: ($data['company_name'] . ' Admin'),
                'email'                => $data['admin_email'],
                'password'             => $tempPassword,          // hashed by the model cast
                'company_id'           => $company->id,
                'role_id'              => $role->id,
                'status'               => 'ACTIVE',
                'must_change_password' => true,
            ]);
        }

        Log::info('Cloud tenant provisioned', [
            'external_tenant_id' => $data['external_tenant_id'],
            'company_id'         => $company->id,
            'new_admin'          => (bool) $tempPassword,
        ]);

        return response()->json([
            'ok'            => true,
            'company_id'    => $company->id,
            'console_url'   => url('/admin'),
            'admin_email'   => $user->email,
            'temp_password' => $tempPassword,   // null when the admin already existed
        ], 201);
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
}
