<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ScopesVisibleEmployees;
use App\Support\ResolvesBusinessDay;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDailySummary;
use App\Models\EmployeeLoginSession;
use App\Models\EmployeeMeetingSession;
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
    use ScopesVisibleEmployees;
    use ResolvesBusinessDay;
    public function report(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $tz = $this->bizTz($request);
        $today = $this->bizToday($tz);
        $fromDate = $request->query('from', $today);      // local Y-m-d, for DATE columns + range gate
        $toDate = $request->query('to', $today);
        $from = Carbon::parse($fromDate, $tz)->startOfDay();   // EPT-20: agent stores LOCAL time — local bounds match stored values
        $to = Carbon::parse($toDate, $tz)->endOfDay();
        $empId = $request->query('employee_id');
        $todayDay = $this->dayUtcBounds($today, $tz);

        $visible = $this->scopedEmployeeIds($request);
        $employees = Employee::where('company_id', $companyId)
            ->when($empId, fn ($q) => $q->where('id', $empId))
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
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

        // Section 14: Meeting time is PRODUCTIVE (never a break). Aggregate meeting
        // session seconds per employee|date so it can be added to productive time.
        $meetings = EmployeeMeetingSession::where('company_id', $companyId)
            ->whereBetween('actual_start_at', [$from, $to])
            ->when($empId, fn ($q) => $q->where('employee_id', $empId))
            ->selectRaw('employee_id, DATE(actual_start_at) d, COALESCE(SUM(duration_seconds),0) secs')
            ->groupBy('employee_id', DB::raw('DATE(actual_start_at)'))->get()
            ->keyBy(fn ($r) => $r->employee_id . '|' . $r->d);

        $rows = [];

        // 1) Completed days from the daily-summary aggregate (fast + accurate).
        $summaries = EmployeeDailySummary::where('company_id', $companyId)
            ->whereBetween('work_date', [$fromDate, $toDate])
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
                'meeting' => (int) ($meetings[$s->employee_id . '|' . $d]->secs ?? 0),
                'score' => (float) $s->productivity_score, 'live' => false,
            ]);
        }

        // 2) TODAY computed live (if in range).
        if ($fromDate <= $today && $toDate >= $today) {
            $att = EmployeeAttendanceLog::where('company_id', $companyId)
                ->whereDate('work_date', $today)
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))
                ->get()->keyBy('employee_id');

            $act = EmployeeActivityEvent::where('company_id', $companyId)
                ->whereDate('started_at', $today)   // EPT-20: agent stores LOCAL time
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))
                ->selectRaw("employee_id, COALESCE(SUM(CASE WHEN event_type='ACTIVE' THEN duration_seconds ELSE 0 END),0) act, COALESCE(SUM(CASE WHEN event_type='IDLE' THEN duration_seconds ELSE 0 END),0) idl")
                ->groupBy('employee_id')->get()->keyBy('employee_id');

            $viol = EmployeeComplianceEvent::where('company_id', $companyId)
                ->whereDate('started_at', $today)   // EPT-20: agent stores LOCAL time
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
                    ? max(0, (($a->check_out_at ? Carbon::parse($a->check_out_at) : now())->diffInSeconds(Carbon::parse($a->check_in_at), true))) : 0;
                // If the attendance check-in span is missing/short, fall back to the
                // tracked span (active + idle) so productivity reflects active-vs-total
                // instead of dividing by an empty 'present' and showing a false 0%.
                $present = max($present, $work + $idle);
                $rows[] = $this->row($emp, $today, [
                    'first_in' => $a?->check_in_at, 'last_out' => $a?->check_out_at,
                    'present' => $present, 'work' => $work, 'idle' => $idle,
                    'break_secs' => (int) ($bk?->secs ?? 0), 'break_count' => (int) ($bk?->cnt ?? 0),
                    'timeouts' => ($timeouts[$emp->id . '|' . $today]->cnt ?? 0),
                    'non_productive' => 0, 'violations' => (int) ($viol[$emp->id]->c ?? 0),
                    'meeting' => (int) ($meetings[$emp->id . '|' . $today]->secs ?? 0),
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
            // Section 14: Meeting time is productive, kept separate from breaks. The
            // productive figure adds meeting time to tracked active time.
            'meeting_seconds' => (int) ($m['meeting'] ?? 0),
            'productive_seconds' => (int) $m['work'] + (int) ($m['meeting'] ?? 0),
            'productivity' => (float) $m['score'],
            'live' => (bool) $m['live'],
        ];
    }
}
