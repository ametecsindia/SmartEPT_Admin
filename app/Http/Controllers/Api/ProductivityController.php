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
use App\Models\EmployeeAppUsageLog;
use App\Models\EmployeeWebsiteUsageLog;
use App\Models\MeetingParticipant;
use App\Models\User;
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
use App\Services\ScoringService;

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
            ->with(['department:id,name', 'team:id,name', 'shift:id,start_time,end_time,break_minutes_allowed'])
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

    /** Part A: the transparent-formula productivity report (auditable), served ALONGSIDE report(). */
    public const FORMULA_VERSION = 'v2.0';

    /** Active-app categories treated as productive / non-productive; the rest are unclassified. */
    private const PRODUCTIVE_CATS = ['PRODUCTIVE', 'CLIENT_REQUIRED', 'CLIENT_SPECIFIC', 'BANKING_CRM', 'TRAINING'];
    private const NONPRODUCTIVE_CATS = ['NON_PRODUCTIVE', 'RESTRICTED', 'BLOCKED'];

    /**
     * GET /api/reports/productivity-v2 — Part A. One row per employee per day with every source
     * and calculated bucket exposed, over a transparent formula:
     *   Actual Production      = Productive Active + Attended Meeting + Approved Manual
     *   Actual Non-Productivity = Non-Productive Active + Idle + Exceeded Break + Offline/Timeout + Unclassified
     *   Productivity %          = Production / (Production + Non-Production) * 100   (N/A when both are 0)
     * All seconds internally; the client formats HH:MM:SS. Role-scoped like the classic report.
     */
    public function reportV2(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $tz = $this->bizTz($request);
        $today = $this->bizToday($tz);
        $fromDate = $request->query('from', $today);
        $toDate = $request->query('to', $today);
        $from = Carbon::parse($fromDate, $tz)->startOfDay();
        $to = Carbon::parse($toDate, $tz)->endOfDay();
        $empId = $request->query('employee_id');

        $visible = $this->scopedEmployeeIds($request);
        $employees = Employee::where('company_id', $companyId)
            ->when($empId, fn ($q) => $q->where('id', $empId))
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->with([
                'branch:id,name', 'department:id,name', 'designation:id,name',
                'team:id,name,team_leader_user_id', 'reportingManager:id,name',
                'shift:id,name,start_time,end_time,break_minutes_allowed',
            ])->get()->keyBy('id');

        if ($employees->isEmpty()) {
            return response()->json(['from' => $fromDate, 'to' => $toDate,
                'formula_version' => self::FORMULA_VERSION, 'count' => 0, 'data' => [], 'totals' => null]);
        }

        $leadIds = $employees->pluck('team.team_leader_user_id')->filter()->unique()->values()->all();
        $leads = $leadIds ? User::whereIn('id', $leadIds)->pluck('name', 'id') : collect();

        $kb = fn ($r) => $r->employee_id . '|' . $r->d;

        // Productive / non-productive active seconds from app + website category logs.
        $prodIn = "'" . implode("','", self::PRODUCTIVE_CATS) . "'";
        $nonIn  = "'" . implode("','", self::NONPRODUCTIVE_CATS) . "'";
        $catSum = function ($model, $col) use ($companyId, $from, $to, $empId, $prodIn, $nonIn) {
            return $model::where('company_id', $companyId)
                ->whereBetween($col, [$from, $to])
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))
                ->selectRaw("employee_id, DATE($col) d,
                    COALESCE(SUM(CASE WHEN category IN ($prodIn) THEN duration_seconds ELSE 0 END),0) prod,
                    COALESCE(SUM(CASE WHEN category IN ($nonIn) THEN duration_seconds ELSE 0 END),0) nonprod")
                ->groupBy('employee_id', DB::raw("DATE($col)"))->get();
        };
        $actClass = [];
        foreach ([$catSum(EmployeeAppUsageLog::class, 'start_at'), $catSum(EmployeeWebsiteUsageLog::class, 'start_at')] as $set) {
            foreach ($set as $r) {
                $k = $r->employee_id . '|' . $r->d;
                $actClass[$k]['prod'] = ($actClass[$k]['prod'] ?? 0) + (int) $r->prod;
                $actClass[$k]['nonprod'] = ($actClass[$k]['nonprod'] ?? 0) + (int) $r->nonprod;
            }
        }

        // Breaks by type + count + total.
        $breaks = [];
        foreach (EmployeeBreakLog::where('company_id', $companyId)
            ->whereBetween('start_at', [$from, $to])
            ->when($empId, fn ($q) => $q->where('employee_id', $empId))
            ->selectRaw('employee_id, DATE(start_at) d, break_type, COUNT(*) cnt, COALESCE(SUM(duration_seconds),0) secs')
            ->groupBy('employee_id', DB::raw('DATE(start_at)'), 'break_type')->get() as $r) {
            $k = $r->employee_id . '|' . $r->d;
            $breaks[$k]['count'] = ($breaks[$k]['count'] ?? 0) + (int) $r->cnt;
            $breaks[$k]['total'] = ($breaks[$k]['total'] ?? 0) + (int) $r->secs;
            $bucket = $r->break_type === 'TEA' ? 'tea' : ($r->break_type === 'LUNCH' ? 'lunch' : 'other');
            $breaks[$k][$bucket] = ($breaks[$k][$bucket] ?? 0) + (int) $r->secs;
        }

        $timeouts = EmployeeLoginSession::where('company_id', $companyId)
            ->whereBetween('login_at', [$from, $to])
            ->when($empId, fn ($q) => $q->where('employee_id', $empId))
            ->whereIn('logout_reason', ['LOCK', 'TIMEOUT'])
            ->selectRaw('employee_id, DATE(login_at) d, COUNT(*) cnt')
            ->groupBy('employee_id', DB::raw('DATE(login_at)'))->get()->keyBy($kb);

        // Attended meeting seconds (+ online/offline split) from real sessions.
        $mSess = EmployeeMeetingSession::where('employee_meeting_sessions.company_id', $companyId)
            ->whereBetween('actual_start_at', [$from, $to])
            ->when($empId, fn ($q) => $q->where('employee_meeting_sessions.employee_id', $empId))
            ->join('meetings', 'meetings.id', '=', 'employee_meeting_sessions.meeting_id')
            ->selectRaw("employee_meeting_sessions.employee_id, DATE(actual_start_at) d,
                COALESCE(SUM(duration_seconds),0) secs,
                COALESCE(SUM(CASE WHEN meetings.meeting_mode='online' THEN duration_seconds ELSE 0 END),0) online_secs,
                COALESCE(SUM(CASE WHEN meetings.meeting_mode='offline' THEN duration_seconds ELSE 0 END),0) offline_secs,
                COUNT(DISTINCT meetings.id) attended_count")
            ->groupBy('employee_meeting_sessions.employee_id', DB::raw('DATE(actual_start_at)'))->get()->keyBy($kb);

        // Scheduled meetings (invite list) per employee|date.
        $mSched = MeetingParticipant::where('meeting_participants.company_id', $companyId)
            ->join('meetings', 'meetings.id', '=', 'meeting_participants.meeting_id')
            ->whereBetween('meetings.start_at', [$from, $to])
            ->whereNotIn('meetings.status', ['CANCELLED'])
            ->when($empId, fn ($q) => $q->where('meeting_participants.employee_id', $empId))
            ->selectRaw("meeting_participants.employee_id, DATE(meetings.start_at) d,
                COUNT(*) sched_count,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, meetings.start_at, meetings.end_at)),0) sched_secs")
            ->groupBy('meeting_participants.employee_id', DB::raw('DATE(meetings.start_at)'))->get()->keyBy($kb);

        $rows = [];

        // Completed days from the nightly aggregate.
        foreach (EmployeeDailySummary::where('company_id', $companyId)
            ->whereBetween('work_date', [$fromDate, $toDate])
            ->where('work_date', '!=', $today)
            ->when($empId, fn ($q) => $q->where('employee_id', $empId))->get() as $sm) {
            $emp = $employees[$sm->employee_id] ?? null;
            if (! $emp) continue;
            $d = (string) $sm->work_date;
            $k = $sm->employee_id . '|' . $d;
            $rows[] = $this->rowV2($emp, $d, [
                'first_in' => $sm->first_login_at, 'last_out' => $sm->last_logout_at,
                'present' => (int) $sm->present_seconds, 'active' => (int) $sm->active_seconds, 'idle' => (int) $sm->idle_seconds,
                'late_min' => (int) $sm->late_minutes, 'early_min' => (int) $sm->early_logout_minutes,
                'violations' => (int) $sm->violation_count, 'screenshots' => (int) $sm->screenshot_count, 'live' => false,
            ], $actClass[$k] ?? [], $breaks[$k] ?? [], $timeouts[$k] ?? null, $mSess[$k] ?? null, $mSched[$k] ?? null, $leads);
        }

        // TODAY, live.
        if ($fromDate <= $today && $toDate >= $today) {
            $att = EmployeeAttendanceLog::where('company_id', $companyId)->whereDate('work_date', $today)
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))->get()->keyBy('employee_id');
            $act = EmployeeActivityEvent::where('company_id', $companyId)->whereDate('started_at', $today)
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))
                ->selectRaw("employee_id, COALESCE(SUM(CASE WHEN event_type='ACTIVE' THEN duration_seconds ELSE 0 END),0) act, COALESCE(SUM(CASE WHEN event_type='IDLE' THEN duration_seconds ELSE 0 END),0) idl")
                ->groupBy('employee_id')->get()->keyBy('employee_id');
            $viol = EmployeeComplianceEvent::where('company_id', $companyId)->whereDate('started_at', $today)
                ->when($empId, fn ($q) => $q->where('employee_id', $empId))
                ->selectRaw('employee_id, COUNT(*) c')->groupBy('employee_id')->get()->keyBy('employee_id');
            foreach ($employees as $emp) {
                $a = $att[$emp->id] ?? null; $ac = $act[$emp->id] ?? null;
                $k = $emp->id . '|' . $today;
                if (! $a && ! $ac && empty($breaks[$k]) && empty($actClass[$k]) && ! isset($mSess[$k])) continue;
                $work = (int) ($ac->act ?? 0); $idle = (int) ($ac->idl ?? 0);
                $present = ($a && $a->check_in_at)
                    ? max(0, (($a->check_out_at ? Carbon::parse($a->check_out_at) : now())->diffInSeconds(Carbon::parse($a->check_in_at), true))) : 0;
                $present = max($present, $work + $idle);
                $rows[] = $this->rowV2($emp, $today, [
                    'first_in' => $a?->check_in_at, 'last_out' => $a?->check_out_at,
                    'present' => $present, 'active' => $work, 'idle' => $idle,
                    'late_min' => 0, 'early_min' => 0, 'violations' => (int) ($viol[$emp->id]->c ?? 0), 'screenshots' => 0, 'live' => true,
                ], $actClass[$k] ?? [], $breaks[$k] ?? [], $timeouts[$k] ?? null, $mSess[$k] ?? null, $mSched[$k] ?? null, $leads);
            }
        }

        usort($rows, fn ($a, $b) => [$b['work_date'], $a['employee_name']] <=> [$a['work_date'], $b['employee_name']]);

        // Weighted totals (never a simple average of the per-row %).
        $t = ['present' => 0, 'productive_active' => 0, 'meeting' => 0, 'production' => 0,
              'idle' => 0, 'exceeded_break' => 0, 'nonproductivity' => 0, 'calc' => 0];
        foreach ($rows as $r) {
            $t['present'] += $r['present_seconds'];
            $t['productive_active'] += $r['productive_active_seconds'];
            $t['meeting'] += $r['attended_meeting_seconds'];
            $t['production'] += $r['actual_production_seconds'];
            $t['idle'] += $r['idle_seconds'];
            $t['exceeded_break'] += $r['exceeded_break_seconds'];
            $t['nonproductivity'] += $r['actual_nonproductivity_seconds'];
            $t['calc'] += $r['productivity_calc_seconds'];
        }
        $t['weighted_productivity'] = $t['calc'] > 0 ? round($t['production'] / $t['calc'] * 100, 1) : null;

        return response()->json([
            'from' => $fromDate, 'to' => $toDate, 'formula_version' => self::FORMULA_VERSION,
            'count' => count($rows), 'data' => $rows, 'totals' => $t,
        ]);
    }

    /** Build one transparent-formula row. All durations in seconds. */
    private function rowV2(Employee $emp, string $date, array $m, array $cl, array $bk, $timeout, $ms, $msch, $leads): array
    {
        $present = (int) $m['present']; $active = (int) $m['active']; $idle = (int) $m['idle'];

        $prodActive = min($active, (int) ($cl['prod'] ?? 0));
        $nonProdActive = min(max(0, $active - $prodActive), (int) ($cl['nonprod'] ?? 0));
        $unclassActive = max(0, $active - $prodActive - $nonProdActive);

        $totalBreak = (int) ($bk['total'] ?? 0);
        $allotted = ScoringService::allottedBreakSeconds($emp->shift, $present);
        $permittedUsed = min($totalBreak, $allotted);
        $exceededBreak = max(0, $totalBreak - $allotted);

        $attendedMeeting = (int) ($ms->secs ?? 0);
        $meetingsAttended = (int) ($ms->attended_count ?? 0);
        $meetingsScheduled = (int) ($msch->sched_count ?? 0);
        $meetingsMissed = max(0, $meetingsScheduled - $meetingsAttended);

        $approvedManual = 0; // no manual-time-entry feature yet — stubbed, never faked

        // Present time not covered by active/idle/permitted-break/meeting = offline/timeout.
        $offlineGap = max(0, $present - ($active + $idle + $permittedUsed + $attendedMeeting));

        $production = $prodActive + $attendedMeeting + $approvedManual;
        $nonProduction = $nonProdActive + $idle + $exceededBreak + $offlineGap + $unclassActive;
        $calc = $production + $nonProduction;
        $productivity = $calc > 0 ? round($production / $calc * 100, 1) : null; // null => N/A

        $dq = 'Valid'; $remarks = [];
        if ($active === 0 && $present === 0) { $dq = 'Incomplete Data'; $remarks[] = 'No tracked activity or attendance.'; }
        elseif (! $m['live'] && $m['first_in'] && ! $m['last_out']) { $dq = 'Missing Logout'; $remarks[] = 'No logout recorded.'; }
        if ($calc > 0 && abs(($production + $nonProduction) - $calc) > 1) { $dq = 'Calculation Mismatch'; }
        if ($approvedManual === 0) { $remarks[] = 'Approved manual/offline time not configured.'; }

        $lead = ($emp->team && $emp->team->team_leader_user_id) ? ($leads[$emp->team->team_leader_user_id] ?? null) : null;

        return [
            'work_date' => $date,
            'branch' => $emp->branch?->name,
            'department' => $emp->department?->name,
            'reporting_manager' => $emp->reportingManager?->name,
            'team_lead' => $lead,
            'employee_code' => $emp->employee_code,
            'employee_name' => trim($emp->first_name . ' ' . $emp->last_name),
            'designation' => $emp->designation?->name,
            'shift_name' => $emp->shift?->name,
            'shift_start' => $emp->shift?->start_time,
            'shift_end' => $emp->shift?->end_time,
            'first_login' => $m['first_in'] ? Carbon::parse($m['first_in'])->format('H:i') : null,
            'last_logout' => $m['last_out'] ? Carbon::parse($m['last_out'])->format('H:i') : null,
            'present_seconds' => $present,
            'late_seconds' => (int) $m['late_min'] * 60,
            'early_logout_seconds' => (int) $m['early_min'] * 60,
            'offline_timeout_seconds' => $offlineGap,
            'timeout_count' => (int) ($timeout->cnt ?? 0),
            'total_active_seconds' => $active,
            'productive_active_seconds' => $prodActive,
            'nonproductive_active_seconds' => $nonProdActive,
            'unclassified_active_seconds' => $unclassActive,
            'scheduled_meeting_seconds' => (int) ($msch->sched_secs ?? 0),
            'attended_meeting_seconds' => $attendedMeeting,
            'online_meeting_seconds' => (int) ($ms->online_secs ?? 0),
            'offline_meeting_seconds' => (int) ($ms->offline_secs ?? 0),
            'meetings_scheduled' => $meetingsScheduled,
            'meetings_attended' => $meetingsAttended,
            'meetings_missed' => $meetingsMissed,
            'idle_seconds' => $idle,
            'break_count' => (int) ($bk['count'] ?? 0),
            'total_break_seconds' => $totalBreak,
            'allotted_break_seconds' => $allotted,
            'permitted_break_seconds' => $permittedUsed,
            'exceeded_break_seconds' => $exceededBreak,
            'tea_break_seconds' => (int) ($bk['tea'] ?? 0),
            'lunch_break_seconds' => (int) ($bk['lunch'] ?? 0),
            'other_break_seconds' => (int) ($bk['other'] ?? 0),
            'approved_manual_seconds' => $approvedManual,
            'actual_production_seconds' => $production,
            'actual_nonproductivity_seconds' => $nonProduction,
            'productivity_calc_seconds' => $calc,
            'productivity_percent' => $productivity,
            'formula_version' => self::FORMULA_VERSION,
            'violations_count' => (int) $m['violations'],
            'screenshots_count' => (int) $m['screenshots'],
            'data_quality' => $dq,
            'remarks' => trim(implode(' ', $remarks)),
            'live' => (bool) $m['live'],
        ];
    }

    /**
     * POST /api/reports/productivity/rebuild?from=&to= — backfill any MISSING daily summaries for
     * the range on demand. The nightly smartept:daily-summary only runs at 00:30; when the OS
     * scheduler is not running, past days never get summarised and the report shows only today.
     * This rebuilds them synchronously (idempotent, role-scoped) so history appears immediately.
     */
    public function rebuildSummaries(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $tz = $this->bizTz($request);
        $today = $this->bizToday($tz);
        $fromDate = $request->query('from', $today);
        $toDate = $request->query('to', $today);

        $start = Carbon::parse($fromDate);
        $end = Carbon::parse($toDate);
        abort_if($start->diffInDays($end) > 62, 422, 'Please rebuild at most about two months at a time.');

        // ?force=1 RECOMPUTES days that already have a summary — needed after a calculation
        // fix so historical rows pick up the corrected first-login / duration logic. It is
        // non-destructive: buildSummary recomputes in place for THIS range only (never a
        // blanket regeneration of production history).
        $force = $request->boolean('force');

        $visible = $this->scopedEmployeeIds($request);
        $employees = Employee::where('company_id', $companyId)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->where('employment_status', 'ACTIVE')
            ->with('shift')->get();

        // Days already summarised -> skip (keep the rebuild cheap + idempotent) UNLESS forced.
        $existing = $force ? collect() : EmployeeDailySummary::where('company_id', $companyId)
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->whereBetween('work_date', [$fromDate, $toDate])
            ->get(['employee_id', 'work_date'])
            ->mapWithKeys(fn ($r) => [$r->employee_id . '|' . $r->work_date->toDateString() => true]);

        $scoring = app(ScoringService::class);
        $calendar = app(\App\Services\WorkCalendar::class);
        $built = 0;

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $ds = $d->toDateString();
            if ($ds >= $today) {
                continue; // today + future are computed live by the report itself
            }
            foreach ($employees as $emp) {
                if (isset($existing[$emp->id . '|' . $ds])) {
                    continue;
                }
                // Build working days, or any day the employee actually has an attendance row.
                if (! $calendar->isWorkingDay($emp, $ds)
                    && ! EmployeeAttendanceLog::where('employee_id', $emp->id)->where('work_date', $ds)->exists()) {
                    continue;
                }
                $scoring->buildSummary($emp, $ds);
                $built++;
            }
        }

        $this->audit($request, 'PRODUCTIVITY_REBUILD', null, null, [
            'from' => $fromDate, 'to' => $toDate, 'built' => $built, 'force' => $force,
        ]);

        return response()->json(['ok' => true, 'built' => $built, 'forced' => $force,
            'message' => $built > 0
                ? ($built . ' day-summaries ' . ($force ? 'recomputed' : 'rebuilt') . ' for ' . $fromDate . ' to ' . $toDate . '.')
                : 'Nothing to rebuild — those days are already summarised, or have no attendance yet.']);
    }

    private function row(Employee $emp, string $date, array $m): array
    {
        $present = (int) $m['present'];
        $productive = (int) $m['work'] + (int) ($m['meeting'] ?? 0);
        $allotted = ScoringService::allottedBreakSeconds($emp->shift, $present);
        $working = ScoringService::netWorkingSeconds($present, $allotted);
        $productivity = round(min(100, $productive / max(1, $working) * 100), 1);

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
            'productive_seconds' => $productive,
            'allotted_break_seconds' => $allotted,
            'net_working_seconds' => $working,
            'productivity' => $productivity,
            'live' => (bool) $m['live'],
        ];
    }
}
