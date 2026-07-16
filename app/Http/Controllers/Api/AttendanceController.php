<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use App\Services\WorkCalendar;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    use ResolvesAgentContext;

    /**
     * POST /api/agent/attendance-event
     * Handles LOGIN / LOGOUT / LOCK / UNLOCK from the client, maintaining the login
     * session and the day's attendance record (with late / early-logout minutes).
     */
    public function store(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid' => ['required', 'string'],
            'event_type'  => ['required', 'in:LOGIN,LOGOUT,LOCK,UNLOCK'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $this->agentDevice($request, $employee);
        $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        $workDate = $at->toDateString();

        // whereDate lookup: portable across MySQL and sqlite for the date-cast
        // work_date column (a plain firstOrCreate() where-match misses on sqlite).
        // Matches ANY source so a biometric-created row is reused, not duplicated.
        $attendance = EmployeeAttendanceLog::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)->first()
            ?? EmployeeAttendanceLog::create([
                'employee_id' => $employee->id, 'work_date' => $workDate, 'source' => 'CLIENT',
                'company_id' => $employee->company_id, 'device_uuid' => $data['device_uuid'], 'status' => 'PRESENT',
            ]);

        match ($data['event_type']) {
            'LOGIN', 'UNLOCK' => $this->handleLogin($employee, $data['device_uuid'], $at, $attendance, $request->ip()),
            'LOGOUT', 'LOCK'  => $this->handleLogout($employee, $at, $attendance, $data['event_type']),
        };

        EmployeeDevice::where('device_uuid', $data['device_uuid'])->update(['last_sync_at' => now()]);

        return response()->json(['ok' => true], 201);
    }

    private function handleLogin($employee, string $uuid, Carbon $at, EmployeeAttendanceLog $attendance, ?string $ip): void
    {
        // Open a session only if there is no currently-open one.
        $open = EmployeeLoginSession::where('employee_id', $employee->id)
            ->whereNull('logout_at')->latest('login_at')->first();

        if (! $open) {
            EmployeeLoginSession::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'device_uuid' => $uuid, 'session_type' => 'CLIENT',
                'login_at' => $at, 'login_ip' => $ip,
            ]);
        }

        $updates = ['last_activity_at' => $at];
        if (! $attendance->check_in_at) {
            $updates['check_in_at'] = $at;
            $updates['first_activity_at'] = $at;
            $updates['late_minutes'] = $this->lateMinutes($employee, $at);
        }
        $attendance->update($updates);
    }

    private function handleLogout($employee, Carbon $at, EmployeeAttendanceLog $attendance, string $reason): void
    {
        $open = EmployeeLoginSession::where('employee_id', $employee->id)
            ->whereNull('logout_at')->latest('login_at')->first();

        if ($open) {
            $open->update([
                'logout_at' => $at,
                'duration_seconds' => $open->login_at ? (int) $at->diffInSeconds($open->login_at, true) : null,
                'logout_reason' => $reason === 'LOCK' ? 'LOCK' : 'USER',
            ]);
        }

        $attendance->update([
            'check_out_at' => $at,
            'last_activity_at' => $at,
            'early_logout_minutes' => $this->earlyLogoutMinutes($employee, $at),
        ]);
    }

    private function lateMinutes($employee, Carbon $at): int
    {
        $shift = $employee->shift;
        // Weekly offs / holidays carry no shift expectation, so "late" is meaningless
        // there — voluntary work on a non-working day must not be penalised.
        if (! $shift || ! app(WorkCalendar::class)->isWorkingDay($employee, $at->toDateString())) {
            return 0;
        }
        $start = Carbon::parse($at->toDateString() . ' ' . $shift->start_time)->addMinutes((int) $shift->grace_minutes);
        return $at->greaterThan($start) ? (int) $at->diffInMinutes($start, true) : 0;
    }

    private function earlyLogoutMinutes($employee, Carbon $at): int
    {
        $shift = $employee->shift;
        // Same rule as lateMinutes: no early-logout penalty on non-working days.
        if (! $shift || ! app(WorkCalendar::class)->isWorkingDay($employee, $at->toDateString())) {
            return 0;
        }
        $end = Carbon::parse($at->toDateString() . ' ' . $shift->end_time);
        return $at->lessThan($end) ? (int) $end->diffInMinutes($at, true) : 0;
    }
}
