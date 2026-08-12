<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\InstallationLicense;
use App\Models\StorageFile;
use App\Models\User;
use App\Services\LicenseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Tenants screen (12-Aug-2026) — the host/operator's view of every company on
 * this install. Super Admin only.
 *
 * Division of truth (agreed with Ejaz): SmartEPT Central owns the COMMERCIAL
 * lifecycle (orders, billing, invoices, plans); this screen shows what only the
 * product knows — live storage per tenant, devices vs licensed seats, users,
 * last agent activity, licence health. Read-only apart from "validate now".
 */
class TenantController extends Controller
{
    /** GET /api/tenants — Super Admin. All companies with ops + licence rollup. */
    public function index(): JsonResponse
    {
        // One grouped query per aggregate, all unscoped (the caller is super).
        // Storage is the heavy one (evidence files) — cached for 10 minutes.
        $storage = Cache::remember('tenants:storage-by-company', 600, function () {
            return StorageFile::withoutGlobalScopes()
                ->selectRaw('company_id, COUNT(*) AS files, COALESCE(SUM(size_bytes),0) AS bytes')
                ->groupBy('company_id')->get()->keyBy('company_id');
        });

        $devices = EmployeeDevice::withoutGlobalScopes()
            ->selectRaw('company_id, COUNT(*) AS total,'
                . " SUM(CASE WHEN unbound_at IS NULL THEN 1 ELSE 0 END) AS bound,"
                . ' MAX(last_heartbeat_at) AS last_beat')
            ->groupBy('company_id')->get()->keyBy('company_id');

        $employees = Employee::withoutGlobalScopes()
            ->selectRaw('company_id, COUNT(*) AS c')->groupBy('company_id')->pluck('c', 'company_id');

        $users = User::query()
            ->selectRaw('company_id, COUNT(*) AS c')->groupBy('company_id')->pluck('c', 'company_id');

        $licences = InstallationLicense::whereNotNull('company_id')->get()->keyBy('company_id');
        $install = InstallationLicense::current();

        $rows = Company::query()
            ->orderByRaw("CASE WHEN deployment_model = 'AMETECS_SAAS' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(function (Company $c) use ($storage, $devices, $employees, $users, $licences, $install) {
                $isSaas = $c->deployment_model === 'AMETECS_SAAS';
                $lic = $isSaas ? ($licences[$c->id] ?? null) : $install;
                $st = $storage[$c->id] ?? null;
                $dv = $devices[$c->id] ?? null;
                $quotaMb = $c->storage_quota_mb ? (int) $c->storage_quota_mb : null;
                $usedMb = $st ? (int) round($st->bytes / (1024 ** 2)) : 0;

                return [
                    'id'                => $c->id,
                    'name'              => $c->name,
                    'code'              => $c->code,
                    'slug'              => $c->slug,
                    'status'            => $c->status,
                    'deployment_model'  => $c->deployment_model,
                    'is_cloud_tenant'   => $isSaas,
                    'provisioned'       => (bool) $c->external_tenant_id,
                    'console_path'      => $c->slug ? '/' . $c->slug : null,
                    'retention_days'    => $c->data_retention_days,
                    'users'             => (int) ($users[$c->id] ?? 0),
                    'employees'         => (int) ($employees[$c->id] ?? 0),
                    'devices_total'     => (int) ($dv->total ?? 0),
                    'devices_bound'     => (int) ($dv->bound ?? 0),
                    'last_agent_seen'   => $dv && $dv->last_beat ? (string) $dv->last_beat : null,
                    'storage_files'     => (int) ($st->files ?? 0),
                    'storage_used_mb'   => $usedMb,
                    'storage_quota_mb'  => $quotaMb,
                    'storage_pct'       => $quotaMb ? min(999, (int) round($usedMb * 100 / max(1, $quotaMb))) : null,
                    'licence'           => [
                        // 'own' = the tenant's row on this install; 'install' = shared install-level licence.
                        'scope'         => $isSaas ? 'own' : 'install',
                        'configured'    => (bool) $lic?->configured(),
                        'status'        => $lic->status ?? null,
                        'operational'   => (bool) $lic?->operational(),
                        'plan'          => $lic?->planCode(),
                        'kind'          => $lic->bundle['kind'] ?? null,
                        'device_limit'  => $lic?->deviceLimit(),
                        'expires_at'    => optional($lic?->expiresAt())->toDateString(),
                        'last_checked'  => optional($lic?->last_checked_at)->toDateTimeString(),
                        'last_error'    => $lic->last_error ?? null,
                        'evaluation_days_left' => ($lic && ! $lic->configured()) ? $lic->evaluationDaysLeft() : null,
                    ],
                ];
            })->values();

        return response()->json([
            'data' => $rows,
            'install_licence' => [
                'configured'  => $install->configured(),
                'status'      => $install->status,
                'operational' => $install->operational(),
                'company'     => $install->companyName(),
            ],
            'storage_cached_minutes' => 10,
        ]);
    }

    /** POST /api/tenants/{company}/license/validate — phone Central for ONE tenant, now. */
    public function validateLicense(Request $request, Company $company, LicenseClient $client): JsonResponse
    {
        abort_unless($company->deployment_model === 'AMETECS_SAAS', 422,
            'This company uses the install-level licence — validate it from the Licence screen.');

        $licence = $client->validate(InstallationLicense::forCompany($company->id));

        $this->audit($request, 'LICENSE_VALIDATED', InstallationLicense::class, $licence->id, [
            'status' => $licence->status,
            'company_id' => $company->id,
        ]);

        return response()->json(['data' => [
            'configured'   => $licence->configured(),
            'status'       => $licence->status,
            'operational'  => $licence->operational(),
            'plan'         => $licence->planCode(),
            'device_limit' => $licence->deviceLimit(),
            'expires_at'   => optional($licence->expiresAt())->toDateString(),
            'last_error'   => $licence->last_error,
        ]]);
    }
}
