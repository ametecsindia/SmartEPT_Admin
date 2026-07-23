<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentTamperEvent;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * QA Phase 2 (A8) — the agent reports every exit / uninstall / unexpected service-stop
 * attempt here (success, failure, or blocked) so tampering is visible to the admin even
 * though a full-Administrator user can never be absolutely stopped (documented limitation).
 */
class TamperController extends Controller
{
    use ResolvesAgentContext;

    /** POST /api/agent/tamper-attempt */
    public function report(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        $this->agentDevice($request, $employee);

        $data = $request->validate([
            'device_uuid' => ['nullable', 'string'],
            'event_type'  => ['required', 'in:EXIT_ATTEMPT,EXIT_SUCCESS,UNINSTALL_ATTEMPT,SERVICE_STOP,WINDOW_CLOSE_BLOCKED'],
            'outcome'     => ['required', 'in:SUCCESS,FAILED,BLOCKED'],
            'reason'      => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date'],
            'metadata'    => ['nullable', 'array'],
        ]);

        $event = AgentTamperEvent::create([
            'company_id'  => $employee->company_id,
            'employee_id' => $employee->id,
            'device_uuid' => $data['device_uuid'] ?? $request->input('device_uuid'),
            'event_type'  => $data['event_type'],
            'outcome'     => $data['outcome'],
            'reason'      => $data['reason'] ?? null,
            'occurred_at' => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now(),
            'metadata'    => $data['metadata'] ?? null,
        ]);

        return response()->json(['ok' => true, 'id' => $event->id], 201);
    }
}
