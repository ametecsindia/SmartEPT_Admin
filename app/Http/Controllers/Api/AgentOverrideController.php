<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentTamperEvent;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Services\GateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QA Phase 2 (A3 emergency override) — a permissioned admin manually lifts the biometric
 * gate for one employee (door reader down, missed punch, visitor PC). NEVER automatic:
 * a reason is mandatory and the action is recorded against the approver in both the audit
 * log and the agent tamper log.
 */
class AgentOverrideController extends Controller
{
    /** POST /api/agent-override/gate */
    public function gate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'reason'      => ['required', 'string', 'min:3', 'max:1000'],
            'device_uuid' => ['nullable', 'string'],
        ]);

        // Tenant-scoped: findOrFail 404s on a cross-company id (Super Admin bypasses scope).
        $employee = Employee::findOrFail($data['employee_id']);

        // Lift the gate through the existing engine by recording a manual IN punch for today.
        // Best-effort: even if the punch write fails, the override is still logged below.
        try {
            $mappingId = \App\Models\BiometricEmployeeMapping::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->value('biometric_employee_id');

            BiometricLog::create([
                'company_id'          => $employee->company_id,
                'biometric_employee_id' => $mappingId ?: ('OVERRIDE-' . $employee->id),
                'employee_id'         => $employee->id,
                'punch_type'          => 'IN',
                'punched_at'          => now(),
                'raw_log_ref'         => 'ADMIN_GATE_OVERRIDE',
                'processed'           => true,
            ]);
        } catch (\Throwable $e) {
            // fall through — the override record + audit still happen
        }

        AgentTamperEvent::create([
            'company_id'       => $employee->company_id,
            'employee_id'      => $employee->id,
            'device_uuid'      => $data['device_uuid'] ?? null,
            'event_type'       => 'GATE_OVERRIDE',
            'outcome'          => 'GRANTED',
            'reason'           => $data['reason'],
            'approver_user_id' => $request->user()->id,
            'occurred_at'      => now(),
        ]);

        $this->audit($request, 'GATE_OVERRIDE', Employee::class, $employee->id, [
            'reason' => $data['reason'], 'device_uuid' => $data['device_uuid'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'gate' => app(GateService::class)->statusFor($employee),
        ]);
    }
}
