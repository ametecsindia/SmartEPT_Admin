<?php

namespace App\Services;

use App\Models\BiometricLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * QA Phase 3 (B7 + B8 + D2/D3) — the single source of truth that turns RAW
 * punches + agent sessions into the day's derived attendance summary.
 *
 * Design rules (payroll-feeding — treat as authoritative):
 *  - Raw stays raw: biometric_logs are NEVER mutated here. We only read them and
 *    write the summary row (check_in / check_out / late + provenance).
 *  - B7 shift-aware checkout: an intermediate OUT (lunch, a walk to the floor)
 *    is NOT a checkout. Checkout = the TRAILING OUT (the last OUT with no later
 *    IN), reconciled with the agent's real LOGOUT — so a mid-day door OUT can no
 *    longer create an early checkout.
 *  - B8/D2 configurable late: the effective arrival is chosen per the company's
 *    late_arrival_source (agent login / biometric IN / later-of-both / shift).
 *  - Idempotent: re-running on a day (delayed punch, re-sync, nightly) converges
 *    to the same summary. Re-login never moves the late value (first arrival wins).
 *  - Never overwrites an approved MANUAL row (HR regularization) unless an
 *    explicit reconcile flag is passed.
 */
class AttendanceDerivation
{
    public function __construct(private GateService $gate) {}

    /**
     * Recompute the derived summary for one employee/day from raw punches + the
     * existing agent-written session edges. Returns the (updated) attendance row,
     * or null when there is nothing yet to derive.
     */
    public function deriveDay(Employee $employee, string $date, bool $reconcileManual = false): ?EmployeeAttendanceLog
    {
        $companyId = $employee->company_id;
        $company   = $employee->company ?? Company::find($companyId);

        $attendance = EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();

        $punches = $this->dayPunches($employee, $date);

        // Nothing to work with yet — the nightly ABSENT pass will handle empty days.
        if (! $attendance && $punches->isEmpty()) {
            return null;
        }

        // Never silently rewrite an approved manual sheet, or a leave/absent verdict.
        if ($attendance) {
            if ($attendance->source === 'MANUAL' && ! $reconcileManual) {
                return $attendance;
            }
            if (in_array($attendance->status, ['ON_LEAVE', 'ABSENT'], true)) {
                return $attendance;
            }
        }

        $checkoutGrace = (int) ($company->checkout_grace_minutes ?? 30);
        $checkoutPolicy = $company->checkout_policy ?? 'LAST_OUT_AFTER_GRACE';
        $lateSource = $company->late_arrival_source ?? 'LATER_OF_BOTH';

        // ---- raw punch landmarks -------------------------------------------------
        $ins  = $punches->where('punch_type', 'IN')->values();
        $outs = $punches->where('punch_type', 'OUT')->values();

        $firstBioIn = $ins->first()?->punched_at;
        $lastBioIn  = $ins->last()?->punched_at;
        $lastBioOut = $outs->last()?->punched_at;

        // Trailing OUT: the last OUT that is NOT followed by a return IN. A lunch
        // OUT (IN … OUT … IN …) is therefore excluded — it has a later IN.
        $trailingOut = ($lastBioOut && (! $lastBioIn || $lastBioOut->greaterThan($lastBioIn)))
            ? $lastBioOut
            : null;

        // Agent edges already on the row (write-once first login, real final logout).
        $agentLogin  = $attendance?->first_login_at ?: ($attendance?->source === 'CLIENT' ? $attendance?->check_in_at : null);
        $agentLogout = $attendance?->final_logout_at;

        // ---- check-in: earliest real arrival (agent or door) --------------------
        [$checkIn, $checkInSource] = $this->earliest([
            'BIOMETRIC' => $firstBioIn,
            'AGENT'     => $agentLogin,
        ], $attendance?->check_in_at);

        // ---- check-out: shift-aware, biometric OUT never finalises early --------
        [$bioCheckout, $checkoutNote] = $this->biometricCheckout(
            $checkoutPolicy, $trailingOut, $outs, $employee, $date, $checkoutGrace
        );
        [$checkOut, $checkOutSource] = $this->latest([
            'BIOMETRIC' => $bioCheckout,
            'AGENT'     => $agentLogout,
        ]);

        // ---- late minutes (B8/D2) ----------------------------------------------
        $late = $this->lateFor($employee, $date, $agentLogin, $firstBioIn, $checkIn, $lateSource, $company);

        // ---- assemble the write --------------------------------------------------
        $updates = [];
        if ($checkIn && (! $attendance?->check_in_at || ! $checkIn->equalTo($attendance->check_in_at))) {
            $updates['check_in_at'] = $checkIn;
        }
        if ($checkIn) {
            $updates['check_in_source'] = $checkInSource;
        }
        // Checkout may legitimately be nulled: an old premature lunch-OUT checkout is
        // cleared once we see the employee is still inside (trailing IN, no trailing OUT).
        if ($this->differs($checkOut, $attendance?->check_out_at)) {
            $updates['check_out_at'] = $checkOut;
        }
        $updates['check_out_source'] = $checkOut ? $checkOutSource : null;

        if ($late !== null) {
            $updates['late_minutes'] = $late['minutes'];
            $updates['arrival_source_used'] = $late['used'];
        }

        // Early-logout is only meaningful once we have a real checkout and a shift.
        $early = $this->earlyLogoutFor($employee, $date, $checkOut);
        if ($early !== null) {
            $updates['early_logout_minutes'] = $early;
        }

        $updates['derivation_note'] = $this->buildNote($checkInSource, $checkOutSource, $checkOut, $late, $checkoutNote, $checkoutPolicy, $checkoutGrace);

        if (! $attendance) {
            // Punch-only day with no row yet (defensive — merge normally creates it first).
            $attendance = EmployeeAttendanceLog::create(array_merge([
                'company_id'  => $companyId,
                'employee_id' => $employee->id,
                'work_date'   => $date,
                'source'      => 'BIOMETRIC',
                'status'      => 'PRESENT',
            ], $updates));

            return $attendance;
        }

        $attendance->update($updates);

        return $attendance;
    }

    /**
     * Effective-arrival → late minutes, per the company's late_arrival_source (D2).
     * Public so AttendanceController@handleLogin delegates the SAME formula (a
     * biometric-only employee then gets late set too). Returns null when late is
     * not determinable (no shift / non-working day / no arrival known).
     *
     * @return array{minutes:int,used:string,permitted:?Carbon,effective:?Carbon}|null
     */
    public function lateFor(
        Employee $employee,
        string $date,
        ?Carbon $agentLogin,
        ?Carbon $bioIn,
        ?Carbon $fallbackCheckIn = null,
        ?string $lateSource = null,
        ?Company $company = null
    ): ?array {
        $shift = $employee->shift;
        $company ??= $employee->company ?? Company::find($employee->company_id);
        $lateSource ??= $company->late_arrival_source ?? 'LATER_OF_BOTH';

        // No shift expectation, or a weekly-off / holiday → "late" is meaningless.
        if (! $shift || ! $shift->start_time || ! app(WorkCalendar::class)->isWorkingDay($employee, $date)) {
            return null;
        }

        [$effective, $used] = $this->effectiveArrival($lateSource, $agentLogin, $bioIn, $fallbackCheckIn, $company);
        if (! $effective) {
            return null;
        }

        $permitted = Carbon::parse($date . ' ' . $shift->start_time)->addMinutes((int) $shift->grace_minutes);
        $minutes = $effective->greaterThan($permitted) ? (int) $effective->diffInMinutes($permitted, true) : 0;

        return ['minutes' => $minutes, 'used' => $used, 'permitted' => $permitted, 'effective' => $effective];
    }

    /** Resolve the effective arrival instant + a short label of what decided it. */
    private function effectiveArrival(string $lateSource, ?Carbon $agentLogin, ?Carbon $bioIn, ?Carbon $fallback, ?Company $company): array
    {
        switch ($lateSource) {
            case 'BIOMETRIC_IN':
                return [$bioIn ?: $agentLogin, $bioIn ? 'BIOMETRIC_IN' : 'AGENT_LOGIN'];

            case 'AGENT_LOGIN':
                // Biometric-only employee (no agent login) falls back to the door IN.
                return [$agentLogin ?: $bioIn, $agentLogin ? 'AGENT_LOGIN' : 'BIOMETRIC_IN'];

            case 'SHIFT_DEFAULT':
                return [$fallback ?: $agentLogin ?: $bioIn, 'SHIFT_DEFAULT'];

            case 'LATER_OF_BOTH':
            default:
                $gated = $company ? $this->gate->enabledFor($company) : false;
                if ($gated) {
                    // Later of the two when the door actually gates the day.
                    $later = $this->maxCarbon([$agentLogin, $bioIn]);
                    return [$later, 'LATER_OF_BOTH'];
                }
                // No biometric gate → the agent login is the arrival (door is advisory).
                return [$agentLogin ?: $bioIn, $agentLogin ? 'AGENT_LOGIN' : 'BIOMETRIC_IN'];
        }
    }

    /**
     * B7 checkout selection from the day's OUT punches, per checkout_policy.
     * @return array{0:?Carbon,1:string}
     */
    private function biometricCheckout(string $policy, ?Carbon $trailingOut, Collection $outs, Employee $employee, string $date, int $graceMinutes): array
    {
        $shift = $employee->shift;
        $shiftEnd = ($shift && $shift->end_time) ? Carbon::parse($date . ' ' . $shift->end_time) : null;
        if ($shiftEnd && $shift->crosses_midnight) {
            $shiftEnd->addDay();
        }

        switch ($policy) {
            case 'MANUAL':
                // HR sets checkout by hand — punches never derive it.
                return [null, 'checkout=MANUAL (not derived from punches)'];

            case 'EXPLICIT_END_PUNCH':
                // Only a trailing OUT AFTER shift end counts; otherwise wait.
                if ($trailingOut && $shiftEnd && $trailingOut->greaterThanOrEqualTo($shiftEnd)) {
                    return [$trailingOut, 'checkout=trailing OUT after shift end (explicit)'];
                }
                return [null, 'checkout pending explicit end punch after shift end'];

            case 'FINAL_OUT_SHIFT_WINDOW':
                // Last OUT within [shift start, shift end + grace]; else the trailing OUT.
                if ($shiftEnd) {
                    $windowEnd = $shiftEnd->clone()->addMinutes($graceMinutes);
                    $inWindow = $outs->filter(fn ($p) => $p->punched_at->lessThanOrEqualTo($windowEnd))->last()?->punched_at;
                    if ($inWindow) {
                        return [$inWindow, 'checkout=last OUT within shift+grace window'];
                    }
                }
                return [$trailingOut, 'checkout=trailing OUT (no in-window OUT)'];

            case 'LAST_OUT_AFTER_GRACE':
            default:
                // Default D3: the trailing OUT is the checkout. A lunch OUT (followed by a
                // return IN) is excluded by construction, so it can never finalise early.
                return [$trailingOut, 'checkout=trailing OUT (last OUT with no later IN)'];
        }
    }

    /** Early-logout minutes = shift end − checkout, only when both are known. */
    private function earlyLogoutFor(Employee $employee, string $date, ?Carbon $checkOut): ?int
    {
        $shift = $employee->shift;
        if (! $checkOut || ! $shift || ! $shift->end_time
            || ! app(WorkCalendar::class)->isWorkingDay($employee, $date)) {
            return null;
        }
        $end = Carbon::parse($date . ' ' . $shift->end_time);
        if ($shift->crosses_midnight) {
            $end->addDay();
        }

        return $checkOut->lessThan($end) ? (int) $end->diffInMinutes($checkOut, true) : 0;
    }

    /**
     * Raw punches for the work day (overnight-shift aware). Never mutated —
     * read-only landmarks for derivation. App-local time throughout, matching the
     * rest of the biometric pipeline (GateService / mergeIntoAttendance).
     */
    private function dayPunches(Employee $employee, string $date): Collection
    {
        $shift = $employee->shift;

        $start = Carbon::parse($date)->startOfDay();
        $end   = Carbon::parse($date)->endOfDay();

        if ($shift && $shift->crosses_midnight && $shift->start_time && $shift->end_time) {
            // Allow an early pre-shift IN and the next-morning OUT to belong to this day.
            $start = Carbon::parse($date . ' ' . $shift->start_time)->subHours(2);
            $end   = Carbon::parse($date . ' ' . $shift->end_time)->addDay()
                ->addMinutes((int) ($employee->company->checkout_grace_minutes ?? 30) + 120);
        }

        return BiometricLog::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$start, $end])
            ->whereIn('punch_type', ['IN', 'OUT'])
            ->orderBy('punched_at')
            ->get(['id', 'punch_type', 'punched_at']);
    }

    /** Earliest non-null instant across the labelled candidates (+ an existing value). */
    private function earliest(array $labelled, ?Carbon $existing): array
    {
        $best = null;
        $label = null;
        foreach ($labelled as $lab => $when) {
            if ($when && (! $best || $when->lessThan($best))) {
                $best = $when;
                $label = $lab;
            }
        }
        // Preserve an even earlier pre-existing check-in we can't re-derive (never lose time).
        if ($existing && (! $best || $existing->lessThan($best))) {
            $best = $existing;
            $label = $label ?: 'EXISTING';
        }

        return [$best, $label];
    }

    /** Latest non-null instant across the labelled candidates. */
    private function latest(array $labelled): array
    {
        $best = null;
        $label = null;
        foreach ($labelled as $lab => $when) {
            if ($when && (! $best || $when->greaterThan($best))) {
                $best = $when;
                $label = $lab;
            }
        }

        return [$best, $label];
    }

    private function maxCarbon(array $times): ?Carbon
    {
        $best = null;
        foreach ($times as $t) {
            if ($t && (! $best || $t->greaterThan($best))) {
                $best = $t;
            }
        }

        return $best;
    }

    private function differs(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null && $b === null) return false;
        if ($a === null || $b === null) return true;

        return ! $a->equalTo($b);
    }

    private function buildNote(?string $inSrc, ?string $outSrc, ?Carbon $checkOut, ?array $late, string $checkoutNote, string $policy, int $grace): string
    {
        $parts = [];
        $parts[] = 'check-in via ' . ($inSrc ?: 'n/a');
        $parts[] = $checkOut ? ('check-out via ' . ($outSrc ?: 'n/a')) : 'check-out pending';
        $parts[] = $checkoutNote . " [policy={$policy}, grace={$grace}m]";
        if ($late) {
            $parts[] = 'late=' . $late['minutes'] . 'm using ' . $late['used']
                . ($late['permitted'] ? ' (permitted ' . $late['permitted']->format('H:i') . ')' : '');
        }

        return implode(' · ', $parts);
    }
}
