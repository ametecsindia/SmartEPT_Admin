<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDevice;
use App\Models\LocalSyncBatch;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    use ResolvesAgentContext;

    /**
     * POST /api/agent/sync-batch
     * Idempotent batch receipt. The agent drains its offline queue via the individual
     * endpoints; this records the batch by batch_uuid so a resent batch is recognised
     * (idempotency ledger) and the device's sync state is updated in one place.
     */
    public function batch(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid' => ['required', 'string'],
            'batch_uuid'  => ['required', 'string', 'max:64'],
            'event_count' => ['nullable', 'integer', 'min:0'],
            'sync_pending' => ['nullable', 'integer', 'min:0'],
        ]);

        $existing = LocalSyncBatch::withoutGlobalScopes()->where('batch_uuid', $data['batch_uuid'])->first();
        $already = (bool) $existing;

        if (! $already) {
            LocalSyncBatch::create([
                'company_id'  => $employee->company_id,
                'device_uuid' => $data['device_uuid'],
                'batch_uuid'  => $data['batch_uuid'],
                'event_count' => $data['event_count'] ?? 0,
                'received_at' => now(),
                'processed'   => true,
            ]);
        }

        EmployeeDevice::where('device_uuid', $data['device_uuid'])->update([
            'last_sync_at'       => now(),
            'sync_pending_count' => $data['sync_pending'] ?? 0,
        ]);

        return response()->json(['ok' => true, 'already_processed' => $already]);
    }
}
