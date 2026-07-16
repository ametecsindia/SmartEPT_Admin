<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * Working-day awareness: a day counts as a working day for an employee only if
 * it is not a company holiday AND it is one of their shift's working days.
 * Everything that judges attendance (late minutes, auto-absent marking, daily
 * summaries, the payroll register) asks this service instead of assuming
 * every calendar day is workable.
 */
class WorkCalendar
{
    /** No shift assigned (or shift without working_days) → Indian-office default of MON–SAT. */
    public const DEFAULT_WORKING_DAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

    /** Per-request cache: company_id => [Y-m-d => true]. Holiday tables are tiny; load once. */
    private array $holidays = [];

    public function isWorkingDay(Employee $employee, string $date): bool
    {
        $date = Carbon::parse($date)->toDateString();

        return ! $this->isHoliday($employee->company_id, $date)
            && $this->isShiftDay($employee, $date);
    }

    /** Is this weekday part of the employee's shift roster (ignores holidays)? */
    public function isShiftDay(Employee $employee, string $date): bool
    {
        // Carbon 'D' gives Mon/Tue/... — uppercased to match the shifts.working_days format ["MON",...].
        $dow = strtoupper(Carbon::parse($date)->format('D'));

        return in_array($dow, $this->workingDays($employee), true);
    }

    public function isHoliday(int $companyId, string $date): bool
    {
        if (! array_key_exists($companyId, $this->holidays)) {
            $this->holidays[$companyId] = Holiday::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->pluck('holiday_date')
                ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
                ->all();
        }

        return isset($this->holidays[$companyId][$date]);
    }

    /** The employee's effective weekly roster (uppercase 3-letter day codes). */
    public function workingDays(Employee $employee): array
    {
        $days = $employee->shift?->working_days;

        return (is_array($days) && $days !== [])
            ? array_map('strtoupper', $days)
            : self::DEFAULT_WORKING_DAYS;
    }
}
