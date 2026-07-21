<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeBreakLog;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Section 3 & 14 — break report. Every break session with its permitted vs actual
 * time, the excess, and (when over the limit) the employee's reason + optional
 * reviewer remarks. Filterable by org/branch/department/team/employee, break type,
 * date range, and exceeded-only. JSON for the console; CSV with ?format=csv.
 * Meeting is NOT a break and never appears here.
 */
class BreakReportController extends Controller
{
    use ScopesVisibleEmployees;

    private const LABELS = ['TEA' => 'Tea', 'LUNCH' => 'Lunch', 'CUSTOM' => 'Other', 'BIO' => 'Bio', 'MEETING' => 'Meeting', 'TRAINING' => 'Training', 'PRAYER' => 'Prayer'];

    /** GET /api/reports/breaks */
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;
        $from = Carbon::parse($request->query('from', now()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', now()->toDateString()))->endOfDay();

        $visible = $this->scopedEmployeeIds($request);

        $q = EmployeeBreakLog::where('company_id', $companyId)
            ->with('employee:id,employee_code,first_name,last_name,department_id,team_id')
            ->whereBetween('start_at', [$from, $to])
            ->when($visible !== null, fn ($qq) => $qq->whereIn('employee_id', $visible))
            ->when($request->query('break_type'), fn ($qq, $v) => $qq->where('break_type', $v))
            ->when($request->boolean('exceeded'), fn ($qq) => $qq->where('excess_seconds', '>', 0))
            ->orderByDesc('start_at')
            ->limit(5000);

        $rows = $q->get()->map(function ($b) {
            return [
                'date'             => $b->start_at?->toDateString(),
                'employee_code'    => $b->employee?->employee_code,
                'name'             => $b->employee?->fullName(),
                'break_type'       => self::LABELS[$b->break_type] ?? $b->break_type,
                'start_at'         => $b->start_at?->toDateTimeString(),
                'end_at'           => $b->end_at?->toDateTimeString(),
                'permitted_seconds' => $b->permitted_seconds,
                'actual_seconds'   => $b->duration_seconds,
                'excess_seconds'   => (int) ($b->excess_seconds ?? 0),
                'delay_reason'     => $b->delay_reason,
                'review_status'    => $b->review_status ?? 'NONE',
                'reviewer_remarks' => $b->reviewer_remarks,
                'id'               => $b->id,
            ];
        })->values();

        if ($request->query('format') === 'csv') {
            return $this->csv($rows);
        }

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'count' => $rows->count(), 'data' => $rows]);
    }

    /** PUT /api/reports/breaks/{break}/review — reviewer remarks + status (Admin/HR). */
    public function review(Request $request, EmployeeBreakLog $break): JsonResponse
    {
        $data = $request->validate([
            'review_status'    => ['required', 'in:PENDING,REVIEWED,NONE'],
            'reviewer_remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $break->update([
            'review_status'       => $data['review_status'],
            'reviewer_remarks'    => $data['reviewer_remarks'] ?? null,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at'         => now(),
        ]);

        $this->audit($request, 'BREAK_REVIEW', EmployeeBreakLog::class, $break->id, $data);

        return response()->json(['ok' => true]);
    }

    private function csv($rows): StreamedResponse
    {
        $headers = ['Date', 'Employee code', 'Name', 'Break type', 'Start', 'End', 'Permitted (min)', 'Actual (min)', 'Excess (min)', 'Reason', 'Review status', 'Reviewer remarks'];
        $min = fn ($s) => $s === null ? '' : round(((int) $s) / 60, 1);

        return response()->streamDownload(function () use ($rows, $headers, $min) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['date'], $r['employee_code'], $r['name'], $r['break_type'],
                    $r['start_at'], $r['end_at'], $min($r['permitted_seconds']),
                    $min($r['actual_seconds']), $min($r['excess_seconds']),
                    $r['delay_reason'], $r['review_status'], $r['reviewer_remarks'],
                ]);
            }
            fclose($out);
        }, 'break-report.csv', ['Content-Type' => 'text/csv']);
    }
}
