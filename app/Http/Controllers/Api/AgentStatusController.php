<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeIdleLog;
use App\Models\EmployeeLoginSession;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentStatusController extends Controller
{
    use ResolvesAgentContext;

    /**
     * GET /api/agent/gate-status — Biometric Gate (Doc 11 v1.1).
     * The agent polls this (and reads the same block off every heartbeat) to know
     * whether the door punch has lifted the gate. Deliberately OUTSIDE the consent
     * wall: it is a pre-work check, and it exposes only the caller's own punch state.
     */
    public function gateStatus(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        return response()->json(['gate' => app(\App\Services\GateService::class)->stateFor($employee)]);
    }

    /**
     * GET /api/agent/today
     * Server-side truth for the employee's visible dashboard: today's active / idle /
     * break totals and login time. The agent renders these so the numbers can't drift.
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        $today = now()->toDateString();

        $activeSeconds = (int) EmployeeActivityEvent::where('employee_id', $employee->id)
            ->whereDate('started_at', $today)->where('event_type', 'ACTIVE')->sum('duration_seconds');

        $idleFromEvents = (int) EmployeeActivityEvent::where('employee_id', $employee->id)
            ->whereDate('started_at', $today)->where('event_type', 'IDLE')->sum('duration_seconds');
        $idleFromLogs = (int) EmployeeIdleLog::where('employee_id', $employee->id)
            ->whereDate('idle_start', $today)->sum('duration_seconds');
        $idleSeconds = max($idleFromEvents, $idleFromLogs);

        $breakSeconds = (int) EmployeeBreakLog::where('employee_id', $employee->id)
            ->whereDate('start_at', $today)->sum('duration_seconds');

        $firstLogin = EmployeeLoginSession::where('employee_id', $employee->id)
            ->whereDate('login_at', $today)->min('login_at');

        return response()->json([
            'employee'       => ['id' => $employee->id, 'name' => $employee->fullName()],
            'date'           => $today,
            'logged_in_at'   => $firstLogin,
            'active_seconds' => $activeSeconds,
            'idle_seconds'   => $idleSeconds,
            'break_seconds'  => $breakSeconds,
            'server_time'    => now()->toIso8601String(),
        ]);
    }
}
