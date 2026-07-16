<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeBreakLog;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BreakController extends Controller
{
    use ResolvesAgentContext;

    /**
     * POST /api/agent/break-event
     * START opens a break; END closes the latest open break of that type.
     */
    public function store(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid' => ['required', 'string'],
            'action'      => ['required', 'in:START,END'],
            'break_type'  => ['nullable', 'in:TEA,LUNCH,BIO,MEETING,TRAINING,PRAYER,CUSTOM'],
            'source'      => ['nullable', 'in:MANUAL,AUTO_IDLE,BIOMETRIC'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $this->agentDevice($request, $employee);
        $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        $type = $data['break_type'] ?? 'CUSTOM';

        if ($data['action'] === 'START') {
            $break = EmployeeBreakLog::create([
                'company_id'  => $employee->company_id,
                'employee_id' => $employee->id,
                'device_uuid' => $data['device_uuid'],
                'break_type'  => $type,
                'source'      => $data['source'] ?? 'MANUAL',
                'start_at'    => $at,
                'approval_status' => 'NOT_REQUIRED',
            ]);

            return response()->json(['ok' => true, 'break_id' => $break->id], 201);
        }

        $open = EmployeeBreakLog::where('employee_id', $employee->id)
            ->where('break_type', $type)
            ->whereNull('end_at')
            ->latest('start_at')->first();

        if ($open) {
            $open->update([
                'end_at' => $at,
                'duration_seconds' => $open->start_at ? (int) $at->diffInSeconds($open->start_at, true) : null,
            ]);
        }

        return response()->json(['ok' => true], 200);
    }
}
