<?php

namespace App\Services;

use App\Models\BiometricLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use Illuminate\Support\Carbon;

/**
 * Gate-to-PC (Doc 11 USP). Decides whether an employee's PC agent may start a
 * work session today. The gate is "open" when a door/biometric IN punch for
 * the employee has reached SmartEPT for the current work date — from a physical
 * biometric device (BiometricLog) OR a punch pushed through the public API /
 * integration (which lands as an attendance check-in with a gate source).
 */
class GateService
{
    public function statusFor(Employee $employee): array
    {
        $company = $employee->company ?: Company::find($employee->company_id);
        $required = (bool) ($company->gate_enabled ?? false);

        if (! $required) {
            return ['gate_required' => false, 'open' => true, 'punched_in_at' => null,
                'message' => 'No gate policy — the agent can start normally.'];
        }

        $today = now()->toDateString();

        // 1) A real door punch (IN) today.
        $punch = BiometricLog::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereIn('punch_type', ['IN'])
            ->whereDate('punched_at', $today)
            ->orderBy('punched_at')
            ->first();

        // 2) Or a gate-sourced attendance check-in (punch pushed via the API/integration).
        $gateAttendance = null;
        if (! $punch) {
            $gateAttendance = EmployeeAttendanceLog::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $today)
                ->whereIn('source', ['BIOMETRIC', 'API'])
                ->whereNotNull('check_in_at')
                ->first();
        }

        $inAt = $punch?->punched_at ?? optional($gateAttendance)->check_in_at;

        return [
            'gate_required' => true,
            'open' => (bool) $inAt,
            'punched_in_at' => $inAt ? Carbon::parse($inAt)->toIso8601String() : null,
            'message' => $inAt
                ? 'Gate open — door punch received at ' . Carbon::parse($inAt)->format('H:i') . '. Your work session can start.'
                : 'Waiting for your door punch. Please punch IN at the entrance — your PC session (and work clock) start the moment it reaches SmartEPT.',
        ];
    }

    /** True when the agent is ALLOWED to open a session for this employee right now. */
    public function isOpen(Employee $employee): bool
    {
        return $this->statusFor($employee)['open'];
    }
}
