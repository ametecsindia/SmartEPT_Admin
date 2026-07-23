<?php

namespace App\Services;

use App\Models\BiometricDevice;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeLoginSession;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * BIOMETRIC GATE (Doc 11 v1.1 — Ejaz's design, confirmed 16-Jul-2026).
 *
 * The door punch drives the work day:
 *  - No IN punch yet → the agent shows the gate wall; status Inactive.
 *  - IN punch → gate lifts, work session allowed, status Active.
 *  - Mid-day OUT punch while a session is open → automatic "out of office"
 *    break (source BIOMETRIC) + the agent soft-locks. Return IN punch closes
 *    the break to the second. Out+in under 2 minutes merges away.
 *  - Breaks beyond 45 min raise a compliance event; beyond 3 h HR is emailed
 *    (the existing short-day attendance rule then converts the day naturally).
 *
 * "With biometric" follows the ORGANIZATION's device setup (mode auto), with
 * an explicit on/off override for pilots. Companies without biometric devices
 * are never gated — credentials alone start the day, exactly as before.
 */
class GateService
{
    public const MERGE_UNDER_MINUTES = 2;
    public const FLAG_OVER_MINUTES = 45;
    public const NOTIFY_HR_OVER_MINUTES = 180;

    /**
     * Is the gate active for this company?
     * TWO switches exist (merged 18-Jul — both consoles keep working):
     *  - biometric_gate ('auto'|'on'|'off') — the Biometric-screen card. Explicit
     *    on/off ALWAYS wins (off = pilot/observe, nothing gates).
     *  - gate_enabled (bool) — the Gate-to-PC toggle. In 'auto' mode it forces the
     *    gate ON even before a punch device is registered (API/manual punch orgs).
     * In 'auto' with neither: gate follows the device setup, per Ejaz's rule.
     */
    public function enabledFor(Company $company): bool
    {
        return match ($company->biometric_gate ?? 'auto') {
            'on' => true,
            'off' => false,
            default => (bool) ($company->gate_enabled ?? false)
                || BiometricDevice::withoutGlobalScopes()
                    ->where('company_id', $company->id)->where('status', 'ACTIVE')->exists(),
        };
    }

    /**
     * The employee's live gate state, derived from TODAY's latest door punch.
     * IN/BREAK_IN count as inside; OUT/BREAK_OUT as outside; no punch = not arrived.
     */
    public function stateFor(Employee $employee): array
    {
        $company = $employee->company ?? Company::find($employee->company_id);
        $enabled = $company ? $this->enabledFor($company) : false;

        if (! $enabled) {
            return ['enabled' => false, 'state' => 'IN', 'arrived' => true, 'last_punch_at' => null,
                'message' => 'No biometric gate for this organization — sign-in is enough.'];
        }

        $last = BiometricLog::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('punched_at', now()->toDateString())
            ->orderByDesc('punched_at')
            ->first();

        if (! $last) {
            return ['enabled' => true, 'state' => 'OUT', 'arrived' => false, 'last_punch_at' => null,
                'message' => 'Please punch IN at the door to start your work day.'];
        }

        $inside = in_array($last->punch_type, ['IN', 'BREAK_IN'], true);

        return [
            'enabled' => true,
            'state' => $inside ? 'IN' : 'OUT',
            'arrived' => true,
            'last_punch_at' => $last->punched_at->toDateTimeString(),
            'message' => $inside
                ? 'Punched in — your work session is active.'
                : 'You punched OUT — punch IN at the door to resume.',
        ];
    }

    /**
     * Gate-to-PC compatibility API (the other console/agent codepath) — same
     * v1.1 engine underneath, presented in the {gate_required, open} shape.
     * "open" follows v1.1 semantics: a mid-day OUT punch CLOSES the gate again
     * (soft lock) until the return IN punch.
     */
    public function statusFor(Employee $employee): array
    {
        $s = $this->stateFor($employee);
        $open = ! $s['enabled'] || $s['state'] === 'IN';

        return [
            'gate_required' => $s['enabled'],
            'open' => $open,
            'punched_in_at' => $s['last_punch_at'] ? Carbon::parse($s['last_punch_at'])->toIso8601String() : null,
            'message' => $s['message'],
            // QA Phase 2 (A3): a distinct machine-readable reason the agent shows on the
            // gate wall, so a stuck gate is self-diagnosable instead of a mystery.
            'reason' => $open ? null : $this->closedReason($employee, $s),
        ];
    }

    /**
     * Why the gate is closed right now (only called when open == false). Codes:
     * EMPLOYEE_INACTIVE, NO_MAPPING, NO_PUNCH (no IN punch yet today), PUNCHED_OUT
     * (mid-day soft-lock), CONFIG_ERROR. AWAITING_SYNC / DEVICE_OFFLINE / WRONG_ORG
     * are reserved for when they become detectable at this layer.
     */
    private function closedReason(Employee $employee, array $state): string
    {
        try {
            if (($employee->employment_status ?? 'ACTIVE') !== 'ACTIVE') {
                return 'EMPLOYEE_INACTIVE';
            }

            // Punched in earlier but currently OUT → mid-day soft lock.
            if (! empty($state['arrived'])) {
                return 'PUNCHED_OUT';
            }

            // No punch yet today. If a physical reader is registered but this employee
            // has no biometric mapping, the door can never lift their gate → flag it.
            $hasDevice = BiometricDevice::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)->where('status', 'ACTIVE')->exists();
            $mapped = BiometricEmployeeMapping::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)->where('employee_id', $employee->id)->exists();

            if ($hasDevice && ! $mapped) {
                return 'NO_MAPPING';
            }

            return 'NO_PUNCH';
        } catch (\Throwable $e) {
            return 'CONFIG_ERROR';
        }
    }

    /** True when the agent is ALLOWED to run a work session right now. */
    public function isOpen(Employee $employee): bool
    {
        return $this->statusFor($employee)['open'];
    }

    /**
     * v1.1 auto-break engine — called for every stored punch of a mapped employee.
     * Runs only when the company is gated; safe to call for any punch.
     */
    public function processPunch(int $companyId, int $employeeId, string $punchType, Carbon $punchedAt): void
    {
        $company = Company::find($companyId);

        if (! $company || ! $this->enabledFor($company)) {
            return;
        }

        $outward = in_array($punchType, ['OUT', 'BREAK_OUT'], true);

        if ($outward) {
            $this->handleOutPunch($companyId, $employeeId, $punchedAt);
        } else {
            $this->handleInPunch($companyId, $employeeId, $punchedAt);
        }
    }

    /** OUT punch while a session is open → open (or adopt) a door break. */
    private function handleOutPunch(int $companyId, int $employeeId, Carbon $at): void
    {
        $sessionOpen = EmployeeLoginSession::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->whereNull('logout_at')->exists();

        if (! $sessionOpen) {
            return; // evening walk-out after log-off = day closing, not a break
        }

        $open = $this->openBreak($companyId, $employeeId);

        if ($open) {
            // Employee already clicked a break — the door confirms it (source upgrade).
            if ($open->source !== 'BIOMETRIC') {
                $open->update(['source' => 'BIOMETRIC']);
            }

            return;
        }

        EmployeeBreakLog::create([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'break_type' => 'CUSTOM',
            'source' => 'BIOMETRIC',
            'start_at' => $at,
            'approval_status' => 'NOT_REQUIRED',
        ]);

        // QA Phase 1 (dual-write): the door break is BIOMETRIC/auto, so it may switch the
        // timeline (rule D1 only rejects MANUAL switches). Reflect it as an out-of-office
        // OTHER_BREAK segment.
        $this->mirrorDoorBreak($employeeId, $at);
    }

    /** IN punch → close the open door break (merge tiny ones, flag long ones). */
    private function handleInPunch(int $companyId, int $employeeId, Carbon $at): void
    {
        $open = $this->openBreak($companyId, $employeeId);

        if (! $open || ! $open->start_at || $at->lte($open->start_at)) {
            return;
        }

        $seconds = $open->start_at->diffInSeconds($at);

        // Stepped out for under 2 minutes? Not a break — merge it away.
        if ($seconds < self::MERGE_UNDER_MINUTES * 60) {
            $open->delete();
            $this->mirrorDoorReturn($employeeId, $at);

            return;
        }

        $open->update(['end_at' => $at, 'duration_seconds' => $seconds]);
        $this->mirrorDoorReturn($employeeId, $at);

        $minutes = intdiv($seconds, 60);

        if ($minutes > self::FLAG_OVER_MINUTES) {
            EmployeeComplianceEvent::create([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'event_type' => 'EXCESSIVE_DOOR_BREAK',
                'severity' => $minutes > self::NOTIFY_HR_OVER_MINUTES ? 'HIGH' : 'MEDIUM',
                'description' => "Out-of-office break of {$minutes} minutes recorded by the door (limit " . self::FLAG_OVER_MINUTES . ' min).',
                'detected_value' => $minutes . ' min',
                'expected_value' => '<= ' . self::FLAG_OVER_MINUTES . ' min',
                'action_taken' => 'LOGGED',
                'started_at' => $open->start_at,
            ]);
        }

        if ($minutes > self::NOTIFY_HR_OVER_MINUTES) {
            $employee = Employee::withoutGlobalScopes()->find($employeeId);
            $hours = round($minutes / 60, 1);
            $body = ($employee ? $employee->fullName() . ' (' . $employee->employee_code . ')' : 'An employee')
                . " was out of office for {$hours} hours today ({$open->start_at->format('H:i')}–{$at->format('H:i')}, recorded by the biometric door)."
                . "\n\nBeyond 3 hours the day normally counts as a half-day — the attendance sheet applies this automatically tonight; use Attendance → regularize if there is a genuine reason (client visit, medical)."
                . "\n\n— SmartEPT";

            User::query()->where('status', 'ACTIVE')
                ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['SUPER_ADMIN', 'COMPANY_ADMIN', 'HR_ADMIN']))
                ->get(['id', 'email', 'company_id'])
                ->each(fn ($admin) => MailService::send($admin->email, 'SmartEPT: long out-of-office break — ' . ($employee?->fullName() ?? ''), $body, 'gate_long_break', $companyId));
        }
    }

    /** QA Phase 1: reflect a door OUT punch as an out-of-office break in the timeline. */
    private function mirrorDoorBreak(int $employeeId, Carbon $at): void
    {
        try {
            $employee = Employee::withoutGlobalScopes()->find($employeeId);
            if ($employee) {
                app(StatusService::class)->transition($employee, 'OTHER_BREAK', $at, [
                    'manual' => false,
                    'source' => 'BIOMETRIC',
                ]);
            }
        } catch (\Throwable $e) {
            // The timeline mirror must never break punch processing.
        }
    }

    /** QA Phase 1: a return IN punch ends the door break — back to ACTIVE. */
    private function mirrorDoorReturn(int $employeeId, Carbon $at): void
    {
        try {
            $employee = Employee::withoutGlobalScopes()->find($employeeId);
            if (! $employee) {
                return;
            }
            $status = app(StatusService::class);
            if (in_array($status->currentState($employee), StatusService::BREAK_STATES, true)) {
                $status->resumeActive($employee, $at, ['source' => 'BIOMETRIC']);
            }
        } catch (\Throwable $e) {
            // never break punch processing
        }
    }

    private function openBreak(int $companyId, int $employeeId): ?EmployeeBreakLog
    {
        return EmployeeBreakLog::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->whereNull('end_at')
            ->whereDate('start_at', now()->toDateString())
            ->latest('start_at')
            ->first();
    }
}
