<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Services\BiometricCloudSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Biometric device registry. A "device" is either a physical punch reader
 * (middleware push / CSV) or — since 17-Jul — a CLOUD ATTENDANCE API
 * (eTimeOffice-style) that SmartEPT polls hourly for punches.
 */
class BiometricDeviceController extends Controller
{
    /** GET /api/integrations/biometric/devices */
    public function index(Request $request): JsonResponse
    {
        $devices = BiometricDevice::withCount('logs')
            ->orderBy('name')
            ->get()
            ->map(fn ($d) => [
                'id'                   => $d->id,
                'name'                 => $d->name,
                'device_serial'        => $d->device_serial,
                'location'             => $d->location,
                'ip_address'           => $d->ip_address,
                'integration_method'   => $d->integration_method,
                'vendor'               => $d->vendor,
                'status'               => $d->status,
                'branch_id'            => $d->branch_id,
                'last_sync_at'         => $d->last_sync_at?->toDateTimeString(),
                'last_sync_result'     => $d->last_sync_result,
                'logs_count'           => $d->logs_count,
                'sync_enabled'         => (bool) $d->sync_enabled,
                'provider'             => $d->provider,
                'api_base_url'         => $d->api_base_url,
                'api_endpoint'         => $d->api_endpoint,
                'corporate_id'         => $d->corporate_id,
                'api_username'         => $d->api_username,
                'has_password'         => filled($d->api_password),
                'employee_code_filter' => $d->employee_code_filter,
                'employee_id_prefix'   => $d->employee_id_prefix,
                'in_machine_id'        => $d->in_machine_id,
                'out_machine_id'       => $d->out_machine_id,
            ]);

        return response()->json(['data' => $devices]);
    }

    /** POST /api/integrations/biometric/devices */
    public function store(Request $request): JsonResponse
    {
        $device = BiometricDevice::create($this->validated($request));
        $this->audit($request, 'CREATE', BiometricDevice::class, $device->id);

        return response()->json(['data' => $device->fresh()], 201);
    }

    /** PUT /api/integrations/biometric/devices/{device} */
    public function update(Request $request, BiometricDevice $device): JsonResponse
    {
        $device->update($this->validated($request));
        $this->audit($request, 'UPDATE', BiometricDevice::class, $device->id);

        return response()->json(['data' => $device->fresh()]);
    }

    /** DELETE /api/integrations/biometric/devices/{device} */
    public function destroy(Request $request, BiometricDevice $device): JsonResponse
    {
        $this->audit($request, 'DELETE', BiometricDevice::class, $device->id);
        $device->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/integrations/biometric/devices/test-connection
     * Probes the cloud API with the form values WITHOUT saving anything.
     * Pass device_id when editing so a blank password falls back to the stored one.
     */
    public function testConnection(Request $request, BiometricCloudSync $sync): JsonResponse
    {
        $data = $this->validated($request);

        $probe = new BiometricDevice($data);
        $probe->company_id = $request->user()->company_id;

        if (blank($probe->api_password) && $request->filled('device_id')) {
            $saved = BiometricDevice::find((int) $request->input('device_id'));
            if ($saved) {
                $probe->api_password = $saved->api_password;
            }
        }

        $result = $sync->probe($probe);
        $this->audit($request, 'TEST', BiometricDevice::class, $request->input('device_id'), ['ok' => $result['ok']]);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** POST /api/integrations/biometric/devices/{device}/sync — pull punches right now. */
    public function syncNow(Request $request, BiometricDevice $device, BiometricCloudSync $sync): JsonResponse
    {
        try {
            $result = $sync->sync($device);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $this->audit($request, 'SYNC', BiometricDevice::class, $device->id, ['result' => $result['message']]);

        return response()->json($result);
    }

    /** Shared validation. branch_id is tenant-scoped to prevent cross-company references. */
    private function validated(Request $request): array
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name'                 => ['nullable', 'string', 'max:120', 'required_without:provider'],
            'device_serial'        => ['nullable', 'string', 'max:120'],
            'location'             => ['nullable', 'string', 'max:190'],
            'ip_address'           => ['nullable', 'string', 'max:64'],
            'integration_method'   => ['nullable', Rule::in(['DIRECT_PULL', 'MIDDLEWARE_PUSH', 'CSV_IMPORT', 'HRMS_API'])],
            'vendor'               => ['nullable', 'string', 'max:120'],
            'status'               => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'branch_id'            => [
                'nullable',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            // Cloud attendance API (eTimeOffice-style) — Biometric Device Setup form.
            'sync_enabled'         => ['nullable', 'boolean'],
            'provider'             => ['nullable', 'string', 'max:120'],
            'api_base_url'         => ['nullable', 'string', 'max:500'],
            'api_endpoint'         => ['nullable', 'string', 'max:190'],
            'corporate_id'         => ['nullable', 'string', 'max:120'],
            'api_username'         => ['nullable', 'string', 'max:190'],
            'api_password'         => ['nullable', 'string', 'max:190'],
            'employee_code_filter' => ['nullable', 'string', 'max:64'],
            'employee_id_prefix'   => ['nullable', 'string', 'max:32'],
            'in_machine_id'        => ['nullable', 'string', 'max:64'],
            'out_machine_id'       => ['nullable', 'string', 'max:64'],
        ]);

        // The setup form has no separate name field — the provider doubles as the name.
        if (blank($data['name'] ?? null) && filled($data['provider'] ?? null)) {
            $data['name'] = $data['provider'];
        }

        // A blank password on edit means "keep the saved one" — never overwrite with null.
        if (blank($data['api_password'] ?? null)) {
            unset($data['api_password']);
        }

        return $data;
    }
}
