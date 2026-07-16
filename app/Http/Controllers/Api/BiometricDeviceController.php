<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Biometric device registry: the physical punch devices (fingerprint/face/card readers)
 * installed at gates and doors. Punch logs may reference a device via device_id.
 */
class BiometricDeviceController extends Controller
{
    /** GET /api/integrations/biometric/devices */
    public function index(Request $request): JsonResponse
    {
        $devices = BiometricDevice::with('logs')
            ->withCount('logs')
            ->orderBy('name')
            ->get()
            ->map(fn ($d) => [
                'id'                 => $d->id,
                'name'               => $d->name,
                'device_serial'      => $d->device_serial,
                'location'           => $d->location,
                'ip_address'         => $d->ip_address,
                'integration_method' => $d->integration_method,
                'vendor'             => $d->vendor,
                'status'             => $d->status,
                'branch_id'          => $d->branch_id,
                'last_sync_at'       => $d->last_sync_at?->toDateTimeString(),
                'logs_count'         => $d->logs_count,
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

    /** Shared validation. branch_id is tenant-scoped to prevent cross-company references. */
    private function validated(Request $request): array
    {
        $companyId = $request->user()->company_id;

        return $request->validate([
            'name'               => ['required', 'string', 'max:120'],
            'device_serial'      => ['nullable', 'string', 'max:120'],
            'location'           => ['nullable', 'string', 'max:190'],
            'ip_address'         => ['nullable', 'string', 'max:64'],
            'integration_method' => ['nullable', Rule::in(['DIRECT_PULL', 'MIDDLEWARE_PUSH', 'CSV_IMPORT', 'HRMS_API'])],
            'vendor'             => ['nullable', 'string', 'max:120'],
            'status'             => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'branch_id'          => [
                'nullable',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ]);
    }
}
