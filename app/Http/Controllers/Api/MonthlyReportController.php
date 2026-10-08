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

    /** 07-Oct-2026 (Ejaz): register letters, with the legend printed under the Excel sheet. */
    private const LEGEND = [
        'P' => 'Present', 'A' => 'Absent', 'H' => 'Half day', 'L' => 'Leave',
        'WOFF' => 'Weekly off', 'HOL' => 'Holiday', '-' => 'No record / future / not yet joined',
    ];

    /**
     * GET /api/export/attendance-register?month=YYYY-MM  — or ?from=Y-m-d&to=Y-m-d (max 93 days)
     * &format=xlsx for Excel (07-Oct-2026: the Attendance tab's Export), CSV otherwise.
     * One row per employee with their details, one column per date (P/A/H/L, WOFF, HOL), then totals.
     */
    public function attendanceRegister(Request $request, WorkCalendar $calendar)
    {
        [$start, $end] = $this->registerRange($request);
        $this->audit($request, 'EXPORT', EmployeeAttendanceLog::class, null, ['register_from' => $start->toDateString(), 'register_to' => $end->toDateString()]);

        $employees = Employee::with(['shift', 'team', 'department', 'designation', 'branch'])
            ->where('employment_status', 'ACTIVE')
            ->when(($visible = $this->visibleEmployeeIds($request->user())) !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('employee_code')->get();
        $statusByEmployee = $this->statusesByEmployeeAndDate($start, $end);
        $today = now()->toDateString();
        $days = iterator_to_array($start->toPeriod($end));

        $totalsHead = ['Present', 'Absent', 'Half Day', 'Leave', 'Weekly Off', 'Holiday', 'Payable Days'];
        $header = array_merge(
            ['Employee Code', 'Name', 'Branch', 'Department', 'Designation', 'Team', 'Shift', 'Date of Joining'],
            array_map(fn ($d) => $d->format('d-M D'), $days),
            $totalsHead
        );

        $rows = $employees->map(function ($e) use ($calendar, $days, $statusByEmployee, $today) {
            $letters = [];
            foreach ($days as $day) {
                $letters[] = $this->dayLetter($calendar, $e, $day->toDateString(), $statusByEmployee[$e->id] ?? [], $today);
            }

            $tally = array_count_values($letters);
            $p = $tally['P'] ?? 0;
            $h = $tally['H'] ?? 0;
            $l = $tally['L'] ?? 0;

            return array_merge(
                [$e->employee_code, $e->fullName(), $e->branch?->name, $e->department?->name, $e->designation?->name,
                 $e->team?->name, $e->shift?->name, $e->date_of_joining?->format('d-m-Y')],
                $letters,
                [$p, $tally['A'] ?? 0, $h, $l, $tally['WOFF'] ?? 0, $tally['HOL'] ?? 0, $p + 0.5 * $h + $l]
            );
        })->all();

        $name = 'attendance_' . $start->toDateString() . '_' . $end->toDateString();
        if ($request->query('format') === 'xlsx' && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return $this->registerXlsx($request, $name . '.xlsx', $header, $rows, 8, count($days), $start, $end);
        }

        return $this->stream($name . '.csv', $header, $rows);
    }

    /** The register as a formatted Excel sheet: frozen details columns, coloured day codes, legend. */
    private function registerXlsx(Request $request, string $filename, array $header, array $rows, int $detailCols, int $dayCols, Carbon $start, Carbon $end): StreamedResponse
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $book->getActiveSheet()->setTitle('Attendance');
        $col = fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $last = $col(count($header));
        $company = (string) (\App\Models\Company::withoutGlobalScopes()->whereKey($request->user()->company_id)->value('name') ?: '');

        $ws->setCellValue('A1', 'Attendance Register — ' . $company);
        $ws->setCellValue('A2', $start->format('d M Y') . ' to ' . $end->format('d M Y') . ' · generated ' . now()->format('d M Y H:i'));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->fromArray($header, null, 'A4');
        $ws->fromArray(array_map(fn ($r) => array_map(fn ($v) => $v ?? '', $r), $rows), null, 'A5', true);

        $hs = $ws->getStyle('A4:' . $last . '4');
        $hs->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hs->getFill()->setFillType('solid')->getStartColor()->setRGB('0E7C8F');
        $hs->getAlignment()->setWrapText(true)->setVertical('center');
        $ws->getRowDimension(4)->setRowHeight(32);

        $colors = ['P' => 'D1FAE5', 'A' => 'FEE2E2', 'H' => 'FEF3C7', 'L' => 'E0E7FF', 'WOFF' => 'E5E7EB', 'HOL' => 'DBEAFE'];
        $firstDay = $detailCols + 1;
        $n = count($rows);
        for ($i = 0; $i < $n; $i++) {
            for ($c = $firstDay; $c < $firstDay + $dayCols; $c++) {
                $v = (string) ($rows[$i][$c - 1] ?? '');
                if (isset($colors[$v])) {
                    $ws->getStyle($col($c) . ($i + 5))->getFill()->setFillType('solid')->getStartColor()->setRGB($colors[$v]);
                }
            }
        }
        if ($n) {
            $ws->getStyle($col($firstDay) . '4:' . $last . ($n + 4))->getAlignment()->setHorizontal('center');
            $ws->getStyle('A4:' . $last . ($n + 4))->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('D9D4C7');
        }
        for ($c = 1; $c <= $detailCols; $c++) {
            $ws->getColumnDimension($col($c))->setAutoSize(true);
        }
        for ($c = $firstDay; $c < $firstDay + $dayCols; $c++) {
            $ws->getColumnDimension($col($c))->setWidth(7);
        }
        $ws->freezePane($col($firstDay) . '5');

        $r = $n + 6;
        $ws->setCellValue('A' . $r, 'Legend')->getStyle('A' . $r)->getFont()->setBold(true);
        foreach (self::LEGEND as $code => $label) {
            $r++;
            $ws->setCellValue('A' . $r, $code);
            $ws->setCellValue('B' . $r, $label);
            if (isset($colors[$code])) {
                $ws->getStyle('A' . $r)->getFill()->setFillType('solid')->getStartColor()->setRGB($colors[$code]);
            }
        }
        $ws->setCellValue('A' . ($r + 1), 'Payable Days = Present + 0.5 × Half Day + Leave. Weekly offs and holidays are listed separately.');

        return response()->streamDownload(function () use ($book) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function dayLetter(WorkCalendar $calendar, Employee $employee, string $date, array $statuses, string $today): string
    {
        if ($date > $today || ($employee->date_of_joining && $date < $employee->date_of_joining->toDateString())) {
            return '-'; // future, or before they joined
        }
        $st = $statuses[$date] ?? null;
        // Worked on a holiday / weekly off → that still shows as worked.
        if (in_array($st, ['PRESENT', 'HALF_DAY', 'MISMATCH'], true)) {
            return self::STATUS_LETTERS[$st];
        }
        // 07-Oct-2026 (Ejaz): a holiday or weekly off is HOL / WOFF even when the nightly job
        // wrote an ABSENT (or a leave) row for it — those are not absences.
        if ($calendar->isHoliday($employee->company_id, $date)) {
            return 'HOL';
        }
        if (! $calendar->isShiftDay($employee, $date)) {
            return 'WOFF';
        }

        return $st ? (self::STATUS_LETTERS[$st] ?? '-') : '-'; // '-' = working day with no record
    }

    /** ?from&to (max 93 days) or ?month=YYYY-MM (default this month) → [first, last]. */
    private function registerRange(Request $request): array
    {
        if (! $request->filled('from')) {
            return $this->monthRange($request);
        }
        $request->validate(['from' => ['required', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($request->query('from'))->startOfDay();
        $to = Carbon::parse($request->query('to', $request->query('from')))->startOfDay();
        abort_if($from->diffInDays($to) > 92, 422, 'Pick at most 93 days.');

        return [$from, $to];
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
