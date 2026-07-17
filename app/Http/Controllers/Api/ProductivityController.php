<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDailySummary;
use App\Models\EmployeeIdleLog;
use App\Models\EmployeeLoginSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * All-employee, day-wise PRODUCTIVITY report (Ejaz 17-Jul). One row per
 * employee per day: login/logout, working (active) time, idle, breaks (count +
 * time), time-outs, non-productive time, violations and a productivity score —
 * over any from→to range (or week / month), CSV + PDF from the console.
 *
 * Completed days come from the nightly employee_daily_summaries aggregate; TODAY
 * is computed live so managers see the day as it unfolds.
 */
class ProductivityController extends Controller
{
    public function report(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $from = Carbon::parse($request->query('from', now()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', now()->toDateString()))->endOfDay();
        $empId = $request->query('employee_id');
        $today = now()->toDateString();

        $employees = Employee::where('company_id', $companyId)
            ->when($empId, fn ($q) => $q->where('id', $empId))
            ->with(['department:id,name', 'team:id,name'])
            ->get()->keyBy('id');

        // Break counts + timeouts, grouped employee|date, for the whole range.
        $breaks = EmployeeBreakLog::where('company_id', $companyId)
            ->whereBetween('start_at', [$from, $to])
            ->when($empId, fn ($q) => $q->where('employee_id', $empId))
            ->selectRaw('employee_id, DATE(start_at) d, COUNT(*) cnt, COALESCE(SUM(duration_seconds),0) secs')
            ->groupBy('employee_id', DB::raw('DATE(start_at)'))->get()
            ->keyBy(fn ($r) => $r->employee_id . '|' . $r->d);

        $timeouts = EmployeeLoginSession::where('company_id', $companyId)
            ->whereBetween('login_at', [$from, $to])
            ->when($empId, fn ($q) => $q->where('employee_id', $empId))
            ->whereIn('logout_reason', ['LOCK', 'TIMEOUT'])
            ->selectRaw('employee_id, DATE(login_at) d, COUNT(*) cnt')
            ->groupBy('employee_id', DB::raw('DATE(login_at)'))->get()
            ->keyBy(fn ($r) => $r->employee_id . '|' . $r->d);

        $rows = [];

        // 1) Completed days from the daily-summary aggregate (fast + accurate).
        $summaries = EmployeeDailySummary::where('company_id', $companyId)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->where('work_date', '!=', $today)
            ->when($empId, fn ($q) => $q->where('employee_id', $empId))
            ->get();

        foreach ($summaries as $s) {
            $emp = $employees[$s->employee_id] ?? null;
            if (! $emp) continue;
            $d = (string) $s->work_date;
            $bk = $breaks[$s->employee_id . '|' . $d] ?? null;
            $rows[] = $this->row($emp, $d, [
                'first_in' => $s->first_login_at, 'last_out' => $s->last_logout_at,
                'present' => $s->present_seconds, 'work' => $s->active_seconds,
                'idle' => $s->idle_seconds, 'break_secs' => $s->break_seconds,
                'break_count' => $bk?->cnt ?? 0, 'timeouts' => ($timeouts[$s->employee_id . '|' . $d]->cnt ?? 0),
                'non_productive' => $s->non_productive_seconds, 'violations' => $s->violation_count,
                'score' => (float) $s->productivity_score, 'live' => false,
            ]);
        }

        // 2) TODAY computed live (if in range).
        if ($from->toDateString() <= $today && $to->toDateString() >= $today) {
            $att = EmployeeAttendanceLog::where('company_id', $companyId)
                ->whereDate('work_date', $today)
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))
                ->get()->keyBy('employee_id');

            $act = EmployeeActivityEvent::where('company_id', $companyId)
                ->whereDate('started_at', $today)
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))
                ->selectRaw("employee_id, COALESCE(SUM(CASE WHEN event_type='ACTIVE' THEN duration_seconds ELSE 0 END),0) act, COALESCE(SUM(CASE WHEN event_type='IDLE' THEN duration_seconds ELSE 0 END),0) idl")
                ->groupBy('employee_id')->get()->keyBy('employee_id');

            $viol = EmployeeComplianceEvent::where('company_id', $companyId)
                ->whereDate('started_at', $today)
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))
                ->selectRaw('employee_id, COUNT(*) c')->groupBy('employee_id')->get()->keyBy('employee_id');

            foreach ($employees as $emp) {
                $a = $att[$emp->id] ?? null;
                $ac = $act[$emp->id] ?? null;
                $bk = $breaks[$emp->id . '|' . $today] ?? null;
                // Skip employees with no footprint today to keep the table meaningful.
                if (! $a && ! $ac && ! $bk) continue;
                $work = (int) ($ac->act ?? 0);
                $idle = (int) ($ac->idl ?? 0);
                $present = ($a && $a->check_in_at)
                    ? max(0, (($a->check_out_at ? Carbon::parse($a->check_out_at) : now())->diffInSeconds(Carbon::parse($a->check_in_at)))) : 0;
                $rows[] = $this->row($emp, $today, [
                    'first_in' => $a?->check_in_at, 'last_out' => $a?->check_out_at,
                    'present' => $present, 'work' => $work, 'idle' => $idle,
                    'break_secs' => (int) ($bk?->secs ?? 0), 'break_count' => (int) ($bk?->cnt ?? 0),
                    'timeouts' => ($timeouts[$emp->id . '|' . $today]->cnt ?? 0),
                    'non_productive' => 0, 'violations' => (int) ($viol[$emp->id]->c ?? 0),
                    'score' => $present > 0 ? round($work / max($present, 1) * 100, 1) : 0, 'live' => true,
                ]);
            }
        }

        // Sort: newest date first, then employee name.
        usort($rows, fn ($a, $b) => [$b['work_date'], $a['name']] <=> [$a['work_date'], $b['name']]);

        return response()->json([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'count' => count($rows), 'data' => $rows,
        ]);
    }


    /**
     * GET /api/reports/employee/{employee}/day-logs?date=YYYY-MM-DD
     * SmartPRS-style day drill-down: every login/logout pair (with worked time
     * and the break that followed), all breaks, plus totals — worked, break,
     * idle, punch-pair count.
     */
    public function dayLogs(Request $request, \App\Models\Employee $employee): JsonResponse
    {
        abort_unless($employee->company_id === $request->user()->company_id, 404);
        $date = $request->query('date', now()->toDateString());

        $sessions = EmployeeLoginSession::where('employee_id', $employee->id)
            ->whereDate('login_at', $date)->orderBy('login_at')->get();

        $breaks = EmployeeBreakLog::where('employee_id', $employee->id)
            ->whereDate('start_at', $date)->orderBy('start_at')->get();

        $act = EmployeeActivityEvent::where('employee_id', $employee->id)
            ->whereDate('started_at', $date)
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type='ACTIVE' THEN duration_seconds ELSE 0 END),0) act, COALESCE(SUM(CASE WHEN event_type='IDLE' THEN duration_seconds ELSE 0 END),0) idl")
            ->first();

        $att = EmployeeAttendanceLog::where('employee_id', $employee->id)->whereDate('work_date', $date)->first();

        // Pair each session with the break that started after its logout.
        $pairs = $sessions->map(function ($s, $i) use ($sessions, $breaks) {
            $worked = ($s->login_at && $s->logout_at)
                ? Carbon::parse($s->logout_at)->diffInSeconds(Carbon::parse($s->login_at)) : null;
            $nextLogin = $sessions[$i + 1]->login_at ?? null;
            $breakAfter = $breaks->first(function ($b) use ($s, $nextLogin) {
                if (! $s->logout_at || ! $b->start_at) return false;
                $bs = Carbon::parse($b->start_at);
                return $bs->gte(Carbon::parse($s->logout_at)) && (! $nextLogin || $bs->lt(Carbon::parse($nextLogin)));
            });
            return [
                'in' => $s->login_at ? Carbon::parse($s->login_at)->format('H:i') : '—',
                'out' => $s->logout_at ? Carbon::parse($s->logout_at)->format('H:i') : '—',
                'worked_seconds' => $worked,
                'logout_reason' => $s->logout_reason,
                'break_after_seconds' => $breakAfter?->duration_seconds,
            ];
        });

        return response()->json([
            'date' => $date,
            'employee' => ['name' => trim($employee->first_name . ' ' . $employee->last_name), 'code' => $employee->employee_code],
            'status' => $att?->status,
            'first_in' => optional($att?->check_in_at)->format('H:i'),
            'last_out' => optional($att?->check_out_at)->format('H:i'),
            'totals' => [
                'worked_seconds' => (int) ($act->act ?? 0),
                'idle_seconds' => (int) ($act->idl ?? 0),
                'break_seconds' => (int) $breaks->sum('duration_seconds'),
                'break_count' => $breaks->count(),
                'punch_pairs' => $sessions->count(),
            ],
            'punches' => $pairs->values(),
            'breaks' => $breaks->map(fn ($b) => [
                'type' => $b->break_type,
                'start' => $b->start_at ? Carbon::parse($b->start_at)->format('H:i') : '—',
                'end' => $b->end_at ? Carbon::parse($b->end_at)->format('H:i') : 'ongoing',
                'seconds' => $b->duration_seconds,
                'source' => $b->source,
            ])->values(),
        ]);
    }

    private function row(Employee $emp, string $date, array $m): array
    {
        return [
            'work_date' => $date,
            'employee_code' => $emp->employee_code,
            'name' => trim($emp->first_name . ' ' . $emp->last_name),
            'department' => $emp->department?->name,
            'team' => $emp->team?->name,
            'first_in' => $m['first_in'] ? Carbon::parse($m['first_in'])->format('H:i') : null,
            'last_out' => $m['last_out'] ? Carbon::parse($m['last_out'])->format('H:i') : null,
            'present_seconds' => (int) $m['present'],
            'work_seconds' => (int) $m['work'],
            'idle_seconds' => (int) $m['idle'],
            'break_seconds' => (int) $m['break_secs'],
            'break_count' => (int) $m['break_count'],
            'timeouts' => (int) $m['timeouts'],
            'non_productive_seconds' => (int) $m['non_productive'],
            'violations' => (int) $m['violations'],
            'productivity' => (float) $m['score'],
            'live' => (bool) $m['live'],
        ];
    }
}
