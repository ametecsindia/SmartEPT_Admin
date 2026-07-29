<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDailySummary;
use App\Services\WorkCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\ScopesVisibleEmployees;

/**
 * Monthly payroll pack: the per-employee month summary payroll runs on, plus the
 * classic attendance-register CSV matrix (one letter per employee per day).
 *
 * Payable days = present + 0.5 * half_day + on_leave (paid leave); weekly offs and
 * holidays are not counted as payable here — most Indian payrolls add them
 * separately as paid non-working days.
 */
class MonthlyReportController extends Controller
{
    use ScopesVisibleEmployees;

    /** Attendance status → register letter. MISMATCH still carries presence evidence → P. */
    private const STATUS_LETTERS = [
        'PRESENT' => 'P', 'ABSENT' => 'A', 'HALF_DAY' => 'H', 'ON_LEAVE' => 'L', 'MISMATCH' => 'P',
    ];

    /** When a date somehow has rows from several sources, the most favourable verdict wins. */
    private const STATUS_PRECEDENCE = ['PRESENT', 'HALF_DAY', 'ON_LEAVE', 'MISMATCH', 'ABSENT'];

    /** GET /api/reports/monthly-summary?month=YYYY-MM */
    public function summary(Request $request, WorkCalendar $calendar): JsonResponse
    {
        [$start, $end] = $this->monthRange($request);

        $employees = Employee::with(['shift', 'team:id,name'])
            ->where('employment_status', 'ACTIVE')
            ->when(($visible = $this->visibleEmployeeIds($request->user())) !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('employee_code')->get();

        $statusByEmployee = $this->statusesByEmployeeAndDate($start, $end);

        // One aggregate query over daily summaries instead of one per employee.
        $totals = EmployeeDailySummary::query()
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->selectRaw('employee_id, SUM(active_seconds) as active_seconds, SUM(late_minutes) as late_minutes, AVG(productivity_score) as avg_score')
            ->groupBy('employee_id')->get()->keyBy('employee_id');

        $rows = $employees->map(function ($e) use ($calendar, $start, $end, $statusByEmployee, $totals) {
            $counts = $this->statusCounts($statusByEmployee[$e->id] ?? []);
            $workingDays = 0;
            $holidays = 0;
            foreach ($start->toPeriod($end) as $day) {
                $date = $day->toDateString();
                if ($calendar->isHoliday($e->company_id, $date)) {
                    $holidays++;
                } elseif ($calendar->isShiftDay($e, $date)) {
                    $workingDays++;
                }
            }

            $t = $totals[$e->id] ?? null;

            return [
                'employee_id'            => $e->id,
                'employee_code'          => $e->employee_code,
                'name'                   => $e->fullName(),
                'team'                   => $e->team?->name,
                'working_days_in_month'  => $workingDays,
                'present'                => $counts['PRESENT'],
                'absent'                 => $counts['ABSENT'],
                'half_day'               => $counts['HALF_DAY'],
                'on_leave'               => $counts['ON_LEAVE'],
                'holidays_count'         => $holidays,
                'total_active_seconds'   => (int) ($t->active_seconds ?? 0),
                'total_late_minutes'     => (int) ($t->late_minutes ?? 0),
                'avg_productivity_score' => $t ? round((float) $t->avg_score, 2) : null,
                'payable_days'           => $counts['PRESENT'] + 0.5 * $counts['HALF_DAY'] + $counts['ON_LEAVE'],
            ];
        });

        return response()->json(['month' => $start->format('Y-m'), 'data' => $rows]);
    }

    /**
     * GET /api/export/attendance-register?month=YYYY-MM
     * CSV matrix: one row per employee, one column per day of the month
     * (P/A/H/L, WO weekly off, HD holiday, '-' future or no data), then totals.
     */
    public function attendanceRegister(Request $request, WorkCalendar $calendar): StreamedResponse
    {
        [$start, $end] = $this->monthRange($request);
        $this->audit($request, 'EXPORT', EmployeeAttendanceLog::class, null, ['register_month' => $start->format('Y-m')]);

        $employees = Employee::with(['shift', 'team:id,name'])
            ->where('employment_status', 'ACTIVE')
            ->when(($visible = $this->visibleEmployeeIds($request->user())) !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('employee_code')->get();
        $statusByEmployee = $this->statusesByEmployeeAndDate($start, $end);
        $today = now()->toDateString();

        $header = array_merge(
            ['Employee Code', 'Name', 'Team'],
            array_map(fn ($d) => (string) $d, range(1, $end->day)),
            ['Present', 'Absent', 'Half', 'Leave', 'WO', 'Holidays', 'Payable Days']
        );

        $rows = $employees->map(function ($e) use ($calendar, $start, $end, $statusByEmployee, $today) {
            $letters = [];
            foreach ($start->toPeriod($end) as $day) {
                $letters[] = $this->dayLetter($calendar, $e, $day->toDateString(), $statusByEmployee[$e->id] ?? [], $today);
            }

            $tally = array_count_values($letters);
            $p = $tally['P'] ?? 0;
            $h = $tally['H'] ?? 0;
            $l = $tally['L'] ?? 0;

            return array_merge(
                [$e->employee_code, $e->fullName(), $e->team?->name],
                $letters,
                [$p, $tally['A'] ?? 0, $h, $l, $tally['WO'] ?? 0, $tally['HD'] ?? 0, $p + 0.5 * $h + $l]
            );
        });

        return $this->stream('attendance_register_' . $start->format('Y-m') . '.csv', $header, $rows);
    }

    private function dayLetter(WorkCalendar $calendar, Employee $employee, string $date, array $statuses, string $today): string
    {
        if ($date > $today) {
            return '-'; // future days cannot have a verdict yet
        }
        // Actual attendance beats the calendar: someone who worked a holiday shows P.
        if (isset($statuses[$date])) {
            return self::STATUS_LETTERS[$statuses[$date]] ?? '-';
        }
        if ($calendar->isHoliday($employee->company_id, $date)) {
            return 'HD';
        }
        if (! $calendar->isShiftDay($employee, $date)) {
            return 'WO';
        }

        return '-'; // working day with no data (nightly marking not run yet)
    }

    /** [employee_id => [Y-m-d => status]] for the month, tenant-scoped via the model. */
    private function statusesByEmployeeAndDate(Carbon $start, Carbon $end): array
    {
        $map = [];
        EmployeeAttendanceLog::query()
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get(['employee_id', 'work_date', 'status'])
            ->each(function ($r) use (&$map) {
                $date = $r->work_date->toDateString();
                $current = $map[$r->employee_id][$date] ?? null;
                if ($current === null
                    || array_search($r->status, self::STATUS_PRECEDENCE) < array_search($current, self::STATUS_PRECEDENCE)) {
                    $map[$r->employee_id][$date] = $r->status;
                }
            });

        return $map;
    }

    private function statusCounts(array $statusesByDate): array
    {
        $counts = ['PRESENT' => 0, 'ABSENT' => 0, 'HALF_DAY' => 0, 'ON_LEAVE' => 0];
        foreach ($statusesByDate as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            } elseif ($status === 'MISMATCH') {
                $counts['PRESENT']++; // presence evidence, just conflicting sources
            }
        }

        return $counts;
    }

    /** Validate ?month=YYYY-MM (defaults to the current month) → [firstDay, lastDay]. */
    private function monthRange(Request $request): array
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $start = Carbon::createFromFormat('Y-m-d', $request->query('month', now()->format('Y-m')) . '-01')->startOfDay();

        return [$start->copy(), $start->copy()->endOfMonth()->startOfDay()];
    }

    private function stream(string $filename, array $header, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_null($v) ? '' : (string) $v, (array) $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
