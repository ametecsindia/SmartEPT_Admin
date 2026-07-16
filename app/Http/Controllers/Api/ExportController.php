<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDailySummary;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports (open directly in Excel). XLSX via PhpSpreadsheet is a later add-on; CSV keeps
 * the MVP dependency-free. Every export is audit-logged.
 */
class ExportController extends Controller
{
    /** GET /api/export/attendance?from=&to= */
    public function attendance(Request $request): StreamedResponse
    {
        $from = $request->query('from', now()->toDateString());
        $to = $request->query('to', now()->toDateString());
        $this->audit($request, 'EXPORT', EmployeeAttendanceLog::class, null, compact('from', 'to'));

        $rows = EmployeeAttendanceLog::with('employee:id,employee_code,first_name,last_name')
            ->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get();

        return $this->stream('attendance.csv',
            ['Employee Code', 'Name', 'Date', 'Source', 'Check In', 'Check Out', 'Late Min', 'Early Logout Min', 'Status'],
            $rows->map(fn ($r) => [
                $r->employee?->employee_code, $r->employee?->fullName(), $r->work_date?->toDateString(),
                $r->source, $r->check_in_at, $r->check_out_at, $r->late_minutes, $r->early_logout_minutes, $r->status,
            ]));
    }

    /** GET /api/export/productivity?date= or ?from=&to= — per-employee active/idle/break totals. */
    public function productivity(Request $request): StreamedResponse
    {
        // Range support (from/to) with single-day date= kept for backward compatibility.
        [$from, $to] = $this->range($request, now()->toDateString());
        $this->audit($request, 'EXPORT', EmployeeActivityEvent::class, null, compact('from', 'to'));

        $sumFor = fn ($query, string $col) => $query
            ->whereDate($col, '>=', $from)->whereDate($col, '<=', $to)
            ->select('employee_id', DB::raw('SUM(duration_seconds) as s'))->groupBy('employee_id')->pluck('s', 'employee_id');

        $active = $sumFor(EmployeeActivityEvent::where('event_type', 'ACTIVE'), 'started_at');
        $idle = $sumFor(EmployeeActivityEvent::where('event_type', 'IDLE'), 'started_at');
        $break = $sumFor(EmployeeBreakLog::query(), 'start_at');

        $rows = Employee::where('employment_status', 'ACTIVE')->get()->map(fn ($e) => [
            $e->employee_code, $e->fullName(),
            round(((int) ($active[$e->id] ?? 0)) / 3600, 2),
            round(((int) ($idle[$e->id] ?? 0)) / 3600, 2),
            round(((int) ($break[$e->id] ?? 0)) / 3600, 2),
        ]);

        return $this->stream('productivity_' . ($from === $to ? $from : "{$from}_{$to}") . '.csv',
            ['Employee Code', 'Name', 'Active Hours', 'Idle Hours', 'Break Hours'], $rows);
    }

    /** GET /api/export/compliance?from=&to= */
    public function compliance(Request $request): StreamedResponse
    {
        $from = $request->query('from', now()->toDateString());
        $to = $request->query('to', now()->addDay()->toDateString());
        $this->audit($request, 'EXPORT', EmployeeComplianceEvent::class, null, compact('from', 'to'));

        $rows = EmployeeComplianceEvent::with('employee:id,employee_code,first_name,last_name')
            ->whereBetween('started_at', [$from, $to])->orderBy('started_at')->get();

        return $this->stream('compliance.csv',
            ['Employee Code', 'Name', 'Time', 'Category', 'Type', 'Severity', 'Detected', 'Action'],
            $rows->map(fn ($r) => [
                $r->employee?->employee_code, $r->employee?->fullName(), $r->started_at,
                $r->event_category, $r->event_type, $r->severity, $r->detected_value, $r->action_taken,
            ]));
    }

    /** GET /api/export/daily-summary?date= or ?from=&to= — computed scores + totals per employee. */
    public function dailySummary(Request $request): StreamedResponse
    {
        // Range support (from/to) with single-day date= kept for backward compatibility.
        [$from, $to] = $this->range($request, now()->subDay()->toDateString());
        $this->audit($request, 'EXPORT', EmployeeDailySummary::class, null, compact('from', 'to'));

        $rows = EmployeeDailySummary::with('employee:id,employee_code,first_name,last_name')
            ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)
            ->orderBy('work_date')->get();

        return $this->stream('daily_summary_' . ($from === $to ? $from : "{$from}_{$to}") . '.csv',
            ['Employee Code', 'Name', 'Date', 'Active H', 'Idle H', 'Break H', 'Late Min', 'Violations', 'Productivity', 'Compliance'],
            $rows->map(fn ($r) => [
                $r->employee?->employee_code, $r->employee?->fullName(), $r->work_date?->toDateString(),
                round($r->active_seconds / 3600, 2), round($r->idle_seconds / 3600, 2), round($r->break_seconds / 3600, 2),
                $r->late_minutes, $r->violation_count, $r->productivity_score, $r->compliance_score,
            ]));
    }

    /** Resolve [from, to]: explicit from/to range, else the legacy single date= param. */
    private function range(Request $request, string $default): array
    {
        $date = $request->query('date', $default);
        $from = $request->query('from', $date);
        $to = $request->query('to', $request->query('from') ? $from : $date);

        return [$from, max($from, $to)];
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
