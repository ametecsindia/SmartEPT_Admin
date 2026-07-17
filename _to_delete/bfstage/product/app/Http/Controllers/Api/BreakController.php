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
     *
     * START opens a break; END closes it. Hardened 17-Jul after the first live
     * agent run (Ejaz clicked Tea repeatedly with no UI feedback and 15
     * duplicate opens piled up):
     * - START is IDEMPOTENT: an open break of the same type is returned as-is,
     *   never duplicated. Starting a DIFFERENT type first closes the open one
     *   (you are on exactly one break at a time).
     * - END closes EVERY open break for the employee, whatever its type — the
     *   agent's "End current break" button must always work, even for TEA.
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
            $open = EmployeeBreakLog::where('employee_id', $employee->id)
                ->whereNull('end_at')->latest('start_at')->get();

            // Same-type break already running → this click is a duplicate.
            $same = $open->firstWhere('break_type', $type);
            if ($same) {
                return response()->json(['ok' => true, 'break_id' => $same->id, 'deduped' => true], 200);
            }

            // Switching break types: close whatever is open first (one break at a time).
            foreach ($open as $o) {
                $o->update([
                    'end_at' => $at,
                    'duration_seconds' => $o->start_at ? (int) $at->diffInSeconds($o->start_at, true) : null,
                ]);
            }

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

        // END: close ALL open breaks regardless of the type the agent sent.
        $closed = 0;
        EmployeeBreakLog::where('employee_id', $employee->id)
            ->whereNull('end_at')->get()
            ->each(function ($o) use ($at, &$closed) {
                $o->update([
                    'end_at' => $at,
                    'duration_seconds' => $o->start_at ? (int) $at->diffInSeconds($o->start_at, true) : null,
                ]);
                $closed++;
            });

        return response()->json(['ok' => true, 'closed' => $closed], 200);
    }
}
