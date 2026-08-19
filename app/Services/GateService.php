<?php

namespace App\Services;

use App\Models\BiometricDevice;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use App\Models\Team;
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

    /** Valid values of the per-level gate_mode override (NULL = inherit). */
    public const GATE_MODES = ['REQUIRED', 'EXCLUDED'];

    /** Exclusion precedence, most specific first. */
    public const EXCLUSION_LEVELS = ['DEVICE', 'EMPLOYEE', 'TEAM', 'DEPARTMENT', 'BRANCH'];

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
        // ORGANISATION → Attendance source = "Without biometric device" (AGENT_ONLY).
        // There is no door to punch at, so the gate can NEVER apply — regardless of any
        // gate_enabled flag, biometric_gate override or ACTIVE device row left behind by
        // a biometric trial. Checked FIRST so switching the org back to agent-only always
        // releases the desktop agent immediately (Ejaz, 18-Aug-2026: agents stayed walled
        // on "punch in at the door" after the org was moved off biometric).
        if (($company->attendance_mode ?? 'BIOMETRIC') === 'AGENT_ONLY') {
            return false;
        }

        return match ($company->biometric_gate ?? 'auto') {
            'on' => true,
            'off' => false,
            default => (bool) ($company->gate_enabled ?? false)
                || BiometricDevice::withoutGlobalScopes()
                    ->where('company_id', $company->id)->where('status', 'ACTIVE')->exists(),
        };
    }

    /**
     * STANDING GATE EXCLUSION POLICY (Ejaz, 18-Aug-2026 — "allow login without biometric for
     * a branch / team / selected employees").
     *
     * Walks DEVICE > EMPLOYEE > TEAM > DEPARTMENT > BRANCH and stops at the FIRST level
     * that sets gate_mode. NULL everywhere = no exclusion, the company gate applies as
     * before. Because REQUIRED also stops the walk, a specific level can claw back an
     * exclusion granted above it: exclude the Ahmedabad branch, but mark the security
     * team REQUIRED and they still have to punch in.
     *
     * DATED: each level's setting is only live between gate_mode_from and
     * gate_mode_until (inclusive; either may be NULL for "no bound"). Outside its window
     * a level reads as if unset and the walk continues upward — so "the reader is dead,
     * exclude Ahmedabad 20–22 Aug" re-arms the gate on the 23rd by itself, with no
     * scheduled job and nobody having to remember.
     *
     * This is the standing policy. It does NOT replace the one-off emergency override
     * (POST /agent-override/gate), which stays for "the reader died THIS MORNING and I
     * need this one person working in the next 30 seconds".
     *
     * @return array{excluded: bool, level: ?string, source: ?string, mode: ?string,
     *               from: ?string, until: ?string, reason: ?string}
     */
    public function exclusionFor(Employee $employee, ?EmployeeDevice $device = null): array
    {
        $tz = $this->timezoneFor($employee);
        $candidates = [];

        if ($device) {
            $candidates[] = ['DEVICE', $device, $device->computer_name ?: $device->device_uuid];
        }

        $candidates[] = ['EMPLOYEE', $employee, $employee->employee_code];

        foreach ([['TEAM', Team::class, 'team_id'], ['DEPARTMENT', Department::class, 'department_id'], ['BRANCH', Branch::class, 'branch_id']] as [$level, $model, $key]) {
            if (! $employee->{$key}) {
                continue;
            }

            // withoutGlobalScopes drops the tenant scope because the gate is evaluated on
            // agent (device-token) requests that carry no admin context — the same reason
            // the punch lookups below bypass scopes. It ALSO drops SoftDeletingScope, so
            // both are re-applied by hand: a soft-deleted branch must stop granting its
            // exclusion (an admin who deletes a branch to re-gate its staff expects that),
            // and an org row from another tenant must never be consulted at all.
            $row = $model::withoutGlobalScopes()
                ->whereKey($employee->{$key})
                ->where('company_id', $employee->company_id)
                ->whereNull('deleted_at')
                ->first();

            if ($row) {
                $candidates[] = [$level, $row, $row->name];
            }
        }

        foreach ($candidates as [$level, $row, $source]) {
            $mode = $row->gate_mode ?? null;
            $mode = is_string($mode) ? strtoupper(trim($mode)) : null;

            if (! in_array($mode, self::GATE_MODES, true) || ! $this->windowIsLive($row, $tz)) {
                continue; // unset, unrecognised, or outside its from/until window → inherit
            }

            $result = [
                'excluded' => $mode === 'EXCLUDED',
                'level' => $level,
                'source' => $source,
                'mode' => $mode,
                'from' => $this->dateString($row->gate_mode_from ?? null),
                'until' => $this->dateString($row->gate_mode_until ?? null),
                'reason' => $row->gate_mode_reason ?? null,
            ];

            // Caller named no device (the agent's plain gate-status poll), but a level
            // ABOVE the machine says EXCLUDED. If ANY of this employee's own machines is
            // marked REQUIRED we cannot tell which one is asking, so answer for the most
            // restrictive: report the gate closed rather than dropping the wall on a PC an
            // admin deliberately kept gated. The enforcing endpoints (attendance-event,
            // activity, screenshots) all carry device_uuid and resolve this exactly.
            if (! $device && $result['excluded'] && $this->hasDeviceRequiringPunch($employee, $tz)) {
                return ['excluded' => false, 'level' => 'DEVICE', 'source' => null, 'mode' => 'REQUIRED',
                    'from' => null, 'until' => null,
                    'reason' => 'A machine assigned to this employee requires a door punch.'];
            }

            return $result;
        }

        return ['excluded' => false, 'level' => null, 'source' => null, 'mode' => null,
            'from' => null, 'until' => null, 'reason' => null];
    }

    /**
     * Is this row's gate_mode live today? NULL from = already started; NULL until = never
     * expires. Both bounds are INCLUSIVE, so "until 22 Aug" still covers all of the 22nd.
     * An unparseable date fails SAFE (treated as not live) rather than granting access.
     *
     * EPT-20 applies here: "today" is the TENANT's calendar day, not UTC. An exclusion
     * dated to the 22nd must die at midnight in the customer's own timezone — evaluating
     * it in UTC leaves it lifting the gate through the first 5h30m of the 23rd in
     * Asia/Kolkata, which is precisely the night shift this feature was written for.
     */
    private function windowIsLive(object $row, ?string $tz = null): bool
    {
        $tz = $tz ?: config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->startOfDay();

        try {
            $from = ($row->gate_mode_from ?? null) ? Carbon::parse($row->gate_mode_from, $tz)->startOfDay() : null;
            $until = ($row->gate_mode_until ?? null) ? Carbon::parse($row->gate_mode_until, $tz)->startOfDay() : null;
        } catch (\Throwable $e) {
            return false;
        }

        return ! (($from && $today->lt($from)) || ($until && $today->gt($until)));
    }

    /**
     * The timezone whose calendar day governs an employee's exclusion window: their
     * branch's override if it sets one, else the company default, else the app default.
     * Mirrors ResolvesBusinessDay::bizTz, which does the same for reports.
     */
    public function timezoneFor(Employee $employee): string
    {
        $branchTz = $employee->branch_id
            ? Branch::withoutGlobalScopes()->whereKey($employee->branch_id)
                ->where('company_id', $employee->company_id)->value('timezone')
            : null;

        if ($branchTz) {
            return $branchTz;
        }

        $company = $employee->company ?? Company::find($employee->company_id);

        return $company?->timezone ?: config('app.timezone', 'UTC');
    }

    /**
     * Does this employee own a machine whose gate_mode is a LIVE 'REQUIRED'?
     * Unbound machines are excluded: a retired or admin-unbound PC that still carried
     * REQUIRED would wall an intentionally-excluded employee with no visible cause.
     */
    private function hasDeviceRequiringPunch(Employee $employee, ?string $tz = null): bool
    {
        return EmployeeDevice::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereNull('unbound_at')
            ->whereRaw('UPPER(gate_mode) = ?', ['REQUIRED'])
            ->get(['gate_mode_from', 'gate_mode_until'])
            ->contains(fn ($d) => $this->windowIsLive($d, $tz));
    }

    private function dateString($value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The full resolved chain, for the admin console's "why is this person gated?"
     * panel and for support. Read-only; never used to make the decision itself.
     */
    public function traceFor(Employee $employee, ?EmployeeDevice $device = null): array
    {
        $company = $employee->company ?? Company::find($employee->company_id);
        $exclusion = $this->exclusionFor($employee, $device);

        return [
            'company_gate_enabled' => $company ? $this->enabledFor($company) : false,
            'attendance_mode' => $company?->attendance_mode ?? 'BIOMETRIC',
            'biometric_gate' => $company?->biometric_gate ?? 'auto',
            'exclusion' => $exclusion,
            'effective' => $this->statusFor($employee, $device),
        ];
    }

    /**
     * The employee's live gate state, derived from TODAY's latest door punch.
     * IN/BREAK_IN count as inside; OUT/BREAK_OUT as outside; no punch = not arrived.
     */
    public function stateFor(Employee $employee, ?EmployeeDevice $device = null): array
    {
        $company = $employee->company ?? Company::find($employee->company_id);
        $enabled = $company ? $this->enabledFor($company) : false;

        // A standing exclusion only matters when the company gate is on at all.
        $exclusion = $enabled
            ? $this->exclusionFor($employee, $device)
            : ['excluded' => false, 'level' => null, 'source' => null, 'mode' => null,
                'from' => null, 'until' => null, 'reason' => null];

        if ($exclusion['excluded']) {
            $enabled = false;
        }

        if (! $enabled) {
            return ['enabled' => false, 'state' => 'IN', 'arrived' => true, 'last_punch_at' => null,
                // Only ever name a level that actually EXCLUDED. When the walk stopped on a
                // REQUIRED the level is deliberately NOT reported here — reading
                // excluded_level as "excluded at BRANCH" when the branch said REQUIRED is
                // exactly the misdiagnosis this payload exists to prevent.
                'excluded' => (bool) $exclusion['excluded'],
                'excluded_level' => $exclusion['excluded'] ? $exclusion['level'] : null,
                'excluded_until' => $exclusion['excluded'] ? $exclusion['until'] : null,
                'exclusion_reason' => $exclusion['excluded'] ? $exclusion['reason'] : null,
                'message' => match (true) {
                    (bool) $exclusion['excluded'] => 'Excluded from Gate-to-PC'
                        . ($exclusion['source'] ? ' (' . $exclusion['source'] . ')' : '')
                        . ' — sign-in is enough.',
                    ($company?->attendance_mode ?? 'BIOMETRIC') === 'AGENT_ONLY'
                        => 'This organization records attendance from the agent only — sign-in is enough.',
                    default => 'No biometric gate for this organization — sign-in is enough.',
                }];
        }

        $last = BiometricLog::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('punched_at', now()->toDateString())
            ->orderByDesc('punched_at')
            ->first();

        if (! $last) {
            return ['enabled' => true, 'state' => 'OUT', 'arrived' => false, 'last_punch_at' => null,
                'excluded' => false, 'excluded_level' => null, 'excluded_until' => null,
                'exclusion_reason' => null,
                'message' => 'Please punch IN at the door to start your work day.'];
        }

        $inside = in_array($last->punch_type, ['IN', 'BREAK_IN'], true);

        return [
            'enabled' => true,
            'state' => $inside ? 'IN' : 'OUT',
            'arrived' => true,
            'last_punch_at' => $last->punched_at->toDateTimeString(),
            'excluded' => false,
            'excluded_level' => null,
            'excluded_until' => null,
            'exclusion_reason' => null,
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
    public function statusFor(Employee $employee, ?EmployeeDevice $device = null): array
    {
        $s = $this->stateFor($employee, $device);
        $open = ! $s['enabled'] || $s['state'] === 'IN';

        return [
            'gate_required' => $s['enabled'],
            'open' => $open,
            'punched_in_at' => $s['last_punch_at'] ? Carbon::parse($s['last_punch_at'])->toIso8601String() : null,
            'message' => $s['message'],
            // Standing exclusion (18-Aug-2026): the agent shows "no punch needed" rather
            // than a wall, and support can see WHICH level granted it.
            'excluded' => (bool) ($s['excluded'] ?? false),
            'excluded_level' => $s['excluded_level'] ?? null,
            'excluded_until' => $s['excluded_until'] ?? null,
            'exclusion_reason' => $s['exclusion_reason'] ?? null,
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
    public function isOpen(Employee $employee, ?EmployeeDevice $device = null): bool
    {
        return $this->statusFor($employee, $device)['open'];
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
