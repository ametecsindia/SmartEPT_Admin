<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeMeetingSession;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use App\Support\ScopesVisibleEmployees;

/**
 * Section 2 — meeting scheduling & management (HR / Admin / Manager / TL).
 * Employees never reach these endpoints; the agent joins a meeting through the
 * separate, server-validated agent endpoint. Every change here is audit-logged.
 */
class MeetingController extends Controller
{
    use ScopesVisibleEmployees;

    /** GET /api/meetings — list with participant counts + live status. */
    public function index(Request $request): JsonResponse
    {
        $meetings = Meeting::query()
            ->withCount('participants')
            ->with('creator:id,name')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('start_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('start_at', '<=', $v))
            ->orderByDesc('start_at')
            ->limit(500)
            ->get()
            ->map(fn ($m) => [
                'id'                => $m->id,
                'title'             => $m->title,
                'purpose'           => $m->purpose,
                'meeting_date'      => $m->meeting_date?->toDateString(),
                'start_at'          => $m->start_at?->toDateTimeString(),
                'end_at'            => $m->end_at?->toDateTimeString(),
                'status'            => $this->liveStatus($m),
                'meeting_mode'      => $m->meeting_mode,
                'meeting_link'      => $m->meeting_link,
                'venue'             => $m->venue,
                'host_contact'      => $m->host_contact,
                'participant_count' => $m->participants_count,
                'notes'             => $m->notes,
                'created_by'        => $m->creator?->name,
                'organizer'         => $m->creator?->name,
                'actual_end_at'     => $m->actual_end_at?->toDateTimeString(),
                'is_organizer'      => optional($request->user())->id === $m->created_by_user_id,
                'can_end'           => $this->canEnd($request->user(), $m),
                'reminder_minutes'  => $m->reminder_minutes,
                'created_at'        => $m->created_at?->toDateTimeString(),
            ]);

        return response()->json(['data' => $meetings]);
    }

    /**
     * GET /api/meetings/joinable-now — meetings that are live RIGHT NOW for the calling
     * console user: ones they organise, or whose participant list includes their linked
     * employee. Drives the admin-console Join popup (EPT25-12). The agent has its own
     * employee-side Join; this covers organisers/admins who run meetings from the console.
     */
    public function joinableNow(Request $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $user->employee?->id;

        $meetings = Meeting::query()
            ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
            ->where('start_at', '<=', now())
            ->where(function ($q) use ($user, $employeeId) {
                $q->where('created_by_user_id', $user->id);
                if ($employeeId) {
                    $q->orWhereHas('participants', fn ($p) => $p->where('employee_id', $employeeId));
                }
            })
            ->orderBy('start_at')
            ->limit(20)
            ->get()
            ->filter(fn ($m) => $this->liveStatus($m) === 'IN_PROGRESS')
            ->map(fn ($m) => [
                'id'           => $m->id,
                'title'        => $m->title,
                'start_at'     => $m->start_at?->toDateTimeString(),
                'meeting_mode' => $m->meeting_mode,
                'meeting_link' => $m->meeting_link,
                'venue'        => $m->venue,
                'is_organizer' => $m->created_by_user_id === $user->id,
            ])->values();

        return response()->json(['data' => $meetings]);
    }

    /** GET /api/meetings/{meeting} — full detail incl. participant employee ids. */
    public function show(Request $request, Meeting $meeting): JsonResponse
    {
        $meeting->load('participants:id,meeting_id,employee_id');

        return response()->json(['data' => [
            'id'              => $meeting->id,
            'title'           => $meeting->title,
            'purpose'         => $meeting->purpose,
            'meeting_date'    => $meeting->meeting_date?->toDateString(),
            'start_at'        => $meeting->start_at?->toDateTimeString(),
            'end_at'          => $meeting->end_at?->toDateTimeString(),
            'status'          => $this->liveStatus($meeting),
            'notes'           => $meeting->notes,
            'reminder_minutes'=> $meeting->reminder_minutes,
            'meeting_mode'    => $meeting->meeting_mode,
            'meeting_link'    => $meeting->meeting_link,
            'venue'           => $meeting->venue,
            'host_contact'    => $meeting->host_contact,
            'participant_ids' => $meeting->participants->pluck('employee_id'),
        ]]);
    }

    /** POST /api/meetings */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $data = $this->validateMeeting($request, $companyId);

        $meeting = Meeting::create([
            'title'              => $data['title'],
            'purpose'            => $data['purpose'] ?? null,
            'start_at'           => $data['start_at'],
            'end_at'             => $data['end_at'],
            'meeting_date'       => Carbon::parse($data['start_at'])->toDateString(),
            'notes'              => $data['notes'] ?? null,
            'reminder_minutes'   => $data['reminder_minutes'] ?? null,
            'meeting_mode'       => $data['meeting_mode'] ?? 'online',
            'meeting_link'       => $data['meeting_link'] ?? null,
            'venue'              => $data['venue'] ?? null,
            'host_contact'       => $data['host_contact'] ?? null,
            'status'             => 'SCHEDULED',
            'created_by_user_id' => $request->user()->id,
        ]);

        $this->syncParticipants($meeting, $companyId, $data['participant_ids']);
        $this->audit($request, 'CREATE', Meeting::class, $meeting->id, [
            'title' => $meeting->title, 'participants' => count($data['participant_ids']),
        ]);

        return response()->json(['data' => ['id' => $meeting->id]], 201);
    }

    /** PUT /api/meetings/{meeting} — edit / reschedule / change participants. */
    public function update(Request $request, Meeting $meeting): JsonResponse
    {
        abort_if(in_array($meeting->status, ['CANCELLED', 'COMPLETED'], true), 422,
            'A cancelled or completed meeting cannot be edited.');

        $companyId = $request->user()->company_id;
        $data = $this->validateMeeting($request, $companyId);

        // A meeting that has already started may be EXTENDED / re-scoped, but its start
        // time cannot move into the past or be shifted once it is underway.
        if ($meeting->start_at->isPast() && ! Carbon::parse($data['start_at'])->equalTo($meeting->start_at)) {
            abort(422, 'This meeting has already started — you can extend the end time but not change the start.');
        }

        $meeting->update([
            'title'        => $data['title'],
            'purpose'      => $data['purpose'] ?? null,
            'start_at'     => $data['start_at'],
            'end_at'       => $data['end_at'],
            'meeting_date' => Carbon::parse($data['start_at'])->toDateString(),
            'notes'        => $data['notes'] ?? null,
            'reminder_minutes' => $data['reminder_minutes'] ?? null,
            'meeting_mode'     => $data['meeting_mode'] ?? 'online',
            'meeting_link'     => $data['meeting_link'] ?? null,
            'venue'            => $data['venue'] ?? null,
            'host_contact'     => $data['host_contact'] ?? null,
        ]);

        $this->syncParticipants($meeting, $companyId, $data['participant_ids']);
        $this->audit($request, 'UPDATE', Meeting::class, $meeting->id, [
            'title' => $meeting->title, 'participants' => count($data['participant_ids']),
        ]);

        return response()->json(['data' => ['id' => $meeting->id]]);
    }

    /** POST /api/meetings/{meeting}/cancel — cancel + immediately close any open sessions. */
    public function cancel(Request $request, Meeting $meeting): JsonResponse
    {
        if ($meeting->status !== 'CANCELLED') {
            $meeting->update(['status' => 'CANCELLED']);
            $this->closeOpenSessions($meeting, now());
        }
        $this->audit($request, 'CANCEL', Meeting::class, $meeting->id, ['title' => $meeting->title]);

        return response()->json(['data' => ['id' => $meeting->id, 'status' => 'CANCELLED']]);
    }

    /**
     * POST /api/meetings/{meeting}/end — the ORGANISER ends the meeting NOW, for everyone.
     * (Admin #9) Only the creator/organiser may end it — not other participants or admins.
     * Ending closes the window immediately: the agents' Meeting button disappears on the
     * next heartbeat, open sessions are closed, and the live-board "In Meeting" clears at
     * once (the status-timeline MEETING segment is closed here, not only at the old end).
     */
    public function end(Request $request, Meeting $meeting): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canEnd($user, $meeting), 403,
            'Only the meeting organiser or an admin can end this meeting.');
        abort_if(in_array($meeting->status, ['CANCELLED', 'COMPLETED', 'NO_SHOW', 'AUTO_CLOSED'], true), 422,
            'This meeting is already over.');

        $now = now();
        // Keep the scheduled end_at as the record; actual_end_at is the truth (EPT25-08).
        $meeting->update([
            'actual_end_at'    => $now,
            'ended_by_user_id' => $user->id,
            'status'           => 'COMPLETED',
        ]);
        $this->closeOpenSessions($meeting, $now);

        \App\Models\StatusTimeline::withoutGlobalScopes()
            ->whereNull('ended_at')->where('state', 'MEETING')->where('meeting_id', $meeting->id)
            ->get()
            ->each(function ($seg) use ($now) {
                $end = $seg->started_at->greaterThan($now) ? $seg->started_at : $now;
                $seg->forceFill([
                    'ended_at'         => $end,
                    'duration_seconds' => max(0, $end->getTimestamp() - $seg->started_at->getTimestamp()),
                ])->save();
            });

        $this->audit($request, 'END', Meeting::class, $meeting->id, ['title' => $meeting->title]);

        return response()->json(['data' => ['id' => $meeting->id, 'status' => 'COMPLETED']]);
    }

    /** GET /api/meetings/{meeting}/participation — scheduled vs actual per participant. */
    public function participation(Request $request, Meeting $meeting): JsonResponse
    {
        $meeting->load(['participants.employee:id,employee_code,first_name,last_name', 'creator.employee:id,user_id']);
        $organizerEmployeeId = $meeting->creator?->employee?->id;

        $sessions = EmployeeMeetingSession::where('meeting_id', $meeting->id)
            ->get()
            ->groupBy('employee_id');

        $rows = $meeting->participants->map(function ($p) use ($sessions, $meeting, $organizerEmployeeId) {
            $mine = $sessions->get($p->employee_id) ?? collect();
            $total = (int) $mine->sum('duration_seconds');
            $firstIn = $mine->min('actual_start_at');
            $lastOut = $mine->max('actual_end_at');

            $attended = $mine->isNotEmpty();
            // EPT25-11: the organiser runs the meeting from the admin console (no agent
            // session) — never mark them Absent; they are present as the organiser.
            $isOrganizer = $organizerEmployeeId !== null && $p->employee_id === $organizerEmployeeId;
            // Part B §15: mark ABSENT only once the meeting has actually ended,
            // never while it is still in progress (even if it overran end_at).
            $ended = in_array($this->liveStatus($meeting), ['COMPLETED', 'CANCELLED', 'NO_SHOW', 'AUTO_CLOSED'], true);
            $status = $attended ? 'ATTENDED'
                : ($isOrganizer ? 'ORGANIZER'
                    : ($ended ? 'ABSENT' : 'PENDING'));

            return [
                'employee_id'    => $p->employee_id,
                'name'           => $p->employee?->fullName(),
                'scheduled_start'=> $meeting->start_at?->toDateTimeString(),
                'scheduled_end'  => $meeting->end_at?->toDateTimeString(),
                'actual_start'   => $firstIn ? Carbon::parse($firstIn)->toDateTimeString() : null,
                'actual_end'     => $lastOut ? Carbon::parse($lastOut)->toDateTimeString() : null,
                'actual_seconds' => $total,
                'attendance'     => $status,
            ];
        })->values();

        return response()->json(['data' => [
            'meeting' => ['id' => $meeting->id, 'title' => $meeting->title, 'status' => $this->liveStatus($meeting)],
            'rows'    => $rows,
        ]]);
    }

    /** GET /api/reports/meetings — company-wide meeting attendance report. */
    public function report(Request $request): JsonResponse
    {
        $meetings = Meeting::withCount('participants')
            ->with('creator:id,name')
            ->when($request->from, fn ($q, $v) => $q->whereDate('start_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('start_at', '<=', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('start_at')
            ->limit(1000)
            ->get();

        $sessions = EmployeeMeetingSession::whereIn('meeting_id', $meetings->pluck('id'))
            ->selectRaw('meeting_id, COUNT(DISTINCT employee_id) attended, COALESCE(SUM(duration_seconds),0) secs')
            ->groupBy('meeting_id')->get()->keyBy('meeting_id');

        $rows = $meetings->map(fn ($m) => [
            'id'                => $m->id,
            'title'             => $m->title,
            'date'              => $m->meeting_date?->toDateString(),
            'start_at'          => $m->start_at?->toDateTimeString(),
            'end_at'            => $m->end_at?->toDateTimeString(),
            'actual_end_at'     => $m->actual_end_at?->toDateTimeString(),
            'organizer'         => $m->creator?->name,
            'status'            => $this->liveStatus($m),
            'participants'      => $m->participants_count,
            'attended'          => (int) ($sessions[$m->id]->attended ?? 0),
            'scheduled_seconds' => ($m->start_at && $m->end_at) ? (int) $m->end_at->diffInSeconds($m->start_at, true) : null,
            'actual_seconds'    => (int) ($sessions[$m->id]->secs ?? 0),
        ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/meetings/{meeting}/join — Part B §11-14. The SINGLE join function used by the
     * notification popup, the meeting scheduler and the admin console. Records real attendance
     * (participant row + session) so a joined organiser/participant is never shown Absent, moves
     * the joiner's live status to In Meeting (best-effort), and returns the mode + link so the
     * caller opens Google Meet only AFTER attendance is confirmed.
     */
    public function join(Request $request, Meeting $meeting): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        abort_unless($employee, 422, 'Your login is not linked to an employee record, so meeting attendance cannot be recorded.');

        abort_if(in_array($meeting->status, ['CANCELLED', 'COMPLETED', 'NO_SHOW', 'AUTO_CLOSED'], true),
            422, 'This meeting is not open to join.');

        $isOrganizer = $meeting->created_by_user_id === $user->id;
        $isParticipant = MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('employee_id', $employee->id)->exists();
        abort_unless($isOrganizer || $isParticipant, 403, 'You are not part of this meeting.');

        $source = $request->input('join_source', 'admin_console');
        $now = now();

        // Ensure a participant row (the organiser is often not in the invite list).
        $participant = MeetingParticipant::firstOrCreate(
            ['meeting_id' => $meeting->id, 'employee_id' => $employee->id],
            ['company_id' => $meeting->company_id]
        );
        $participant->forceFill([
            'participant_role'  => $isOrganizer ? 'organizer' : ($participant->participant_role ?: 'participant'),
            'joined_at'         => $participant->joined_at ?: $now,
            'attendance_status' => 'JOINED',
            'join_source'       => $source,
        ])->save();

        // The organiser clicking Start/Join moves the meeting to IN_PROGRESS.
        if ($isOrganizer && $meeting->status === 'SCHEDULED') {
            $meeting->update(['status' => 'IN_PROGRESS']);
        }

        // Attendance session (idempotent) — participation()/reports read these.
        $open = EmployeeMeetingSession::where('meeting_id', $meeting->id)
            ->where('employee_id', $employee->id)->whereNull('actual_end_at')->first();
        if (! $open) {
            EmployeeMeetingSession::create([
                'company_id'      => $meeting->company_id,
                'meeting_id'      => $meeting->id,
                'employee_id'     => $employee->id,
                'actual_start_at' => $now,
            ]);
        }

        // Best-effort live status -> In Meeting. Never blocks the join (an open break
        // conflict or a console user with no agent device simply leaves status as-is).
        try {
            app(\App\Services\StatusService::class)->transition($employee, 'MEETING', $now, [
                'manual' => true, 'source' => 'CONSOLE', 'meeting_id' => $meeting->id,
            ]);
        } catch (\Throwable $e) { /* attendance already recorded */ }

        $this->audit($request, 'MEETING_JOIN', Meeting::class, $meeting->id, [
            'employee_id' => $employee->id,
            'role'        => $isOrganizer ? 'organizer' : 'participant',
            'source'      => $source,
        ]);

        return response()->json(['data' => [
            'meeting_id'        => $meeting->id,
            'meeting_mode'      => $meeting->meeting_mode,
            'meeting_link'      => $meeting->meeting_mode === 'online' ? $meeting->meeting_link : null,
            'venue'             => $meeting->venue,
            'attendance_status' => 'JOINED',
            'status'            => $this->liveStatus($meeting),
        ]]);
    }

    /**
     * POST /api/meetings/{meeting}/leave — Part B §17. Closes the caller's open session(s),
     * records left_at + attended_seconds on the participant row, and restores their live status.
     */
    public function leave(Request $request, Meeting $meeting): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        abort_unless($employee, 422, 'Your login is not linked to an employee record.');

        $now = now();
        $closed = 0;
        EmployeeMeetingSession::where('meeting_id', $meeting->id)
            ->where('employee_id', $employee->id)->whereNull('actual_end_at')->get()
            ->each(function ($s) use ($now, &$closed) {
                $end = $s->actual_start_at && $now->lessThan($s->actual_start_at) ? $s->actual_start_at : $now;
                $s->update([
                    'actual_end_at'    => $end,
                    'duration_seconds' => $s->actual_start_at ? (int) $end->diffInSeconds($s->actual_start_at, true) : 0,
                ]);
                $closed++;
            });

        $participant = MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('employee_id', $employee->id)->first();
        if ($participant) {
            $total = (int) EmployeeMeetingSession::where('meeting_id', $meeting->id)
                ->where('employee_id', $employee->id)->sum('duration_seconds');
            $participant->forceFill([
                'left_at'           => $now,
                'attendance_status' => 'LEFT',
                'attended_seconds'  => $total,
            ])->save();
        }

        try {
            $status = app(\App\Services\StatusService::class);
            if ($status->currentState($employee) === 'MEETING') {
                $status->resumeActive($employee, $now, []);
            }
        } catch (\Throwable $e) { /* status mirror is best-effort */ }

        $this->audit($request, 'MEETING_LEAVE', Meeting::class, $meeting->id, [
            'employee_id' => $employee->id, 'closed_sessions' => $closed,
        ]);

        return response()->json(['data' => ['meeting_id' => $meeting->id, 'left' => true, 'closed_sessions' => $closed]]);
    }

    // ---- helpers ----

    private function validateMeeting(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'title'             => ['required', 'string', 'max:200'],
            'purpose'           => ['nullable', 'string', 'max:2000'],
            'start_at'          => ['required', 'date'],
            'end_at'            => ['required', 'date', 'after:start_at'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'reminder_minutes'  => ['nullable', 'integer', 'min:0', 'max:1440'],
            'meeting_mode'      => ['required', 'in:online,offline'],
            'meeting_link'      => ['nullable', 'url', 'max:1000', 'required_if:meeting_mode,online'],
            'venue'             => ['nullable', 'string', 'max:500', 'required_if:meeting_mode,offline'],
            'host_contact'      => ['nullable', 'string', 'max:255'],
            'participant_ids'   => ['required', 'array', 'min:1', 'max:1000'],
            'participant_ids.*' => ['integer', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
        ]);

        // §18: a restricted organiser (manager/team-lead/branch-admin) may only
        // invite employees inside their own reporting scope.
        $visible = $this->visibleEmployeeIds($request->user());
        if ($visible !== null) {
            $outside = array_diff(array_map('intval', $data['participant_ids']), $visible);
            abort_if(! empty($outside), 403, 'You can only invite employees within your reporting scope.');
        }

        return $data;
    }

    private function syncParticipants(Meeting $meeting, int $companyId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        MeetingParticipant::where('meeting_id', $meeting->id)->whereNotIn('employee_id', $ids)->delete();

        $existing = MeetingParticipant::where('meeting_id', $meeting->id)->pluck('employee_id')->all();
        foreach (array_diff($ids, $existing) as $eid) {
            MeetingParticipant::create([
                'company_id'  => $companyId,
                'meeting_id'  => $meeting->id,
                'employee_id' => $eid,
            ]);
        }
    }

    private function closeOpenSessions(Meeting $meeting, Carbon $at): void
    {
        EmployeeMeetingSession::where('meeting_id', $meeting->id)
            ->whereNull('actual_end_at')
            ->get()
            ->each(function ($s) use ($at) {
                $end = $s->actual_start_at && $at->lessThan($s->actual_start_at) ? $s->actual_start_at : $at;
                $s->update([
                    'actual_end_at'    => $end,
                    'duration_seconds' => $s->actual_start_at ? (int) $end->diffInSeconds($s->actual_start_at, true) : 0,
                ]);
            });
    }

    /** Derived live status (SCHEDULED → IN_PROGRESS → COMPLETED) unless CANCELLED. */
    private function liveStatus(Meeting $meeting): string
    {
        // Terminal statuses are authoritative (set by end() / cancel() / long-stop).
        // EPT25-08: passing the scheduled end alone does NOT complete a meeting — it
        // stays IN_PROGRESS (overrunning) until someone actually ends it.
        if (in_array($meeting->status, ['CANCELLED', 'COMPLETED', 'NO_SHOW', 'AUTO_CLOSED'], true)) {
            return $meeting->status;
        }
        if ($meeting->start_at && $meeting->start_at->lte(now())) {
            return 'IN_PROGRESS';
        }

        return 'SCHEDULED';
    }

    /** EPT25-08: the organiser OR a company/branch/HR/super admin may End a meeting. */
    private function canEnd($user, Meeting $meeting): bool
    {
        if (! $user) {
            return false;
        }

        return $meeting->created_by_user_id === $user->id
            || $user->hasRole('SUPER_ADMIN', 'COMPANY_ADMIN', 'BRANCH_ADMIN', 'HR_ADMIN');
    }
}
