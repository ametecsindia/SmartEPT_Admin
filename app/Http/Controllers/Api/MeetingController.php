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

/**
 * Section 2 — meeting scheduling & management (HR / Admin / Manager / TL).
 * Employees never reach these endpoints; the agent joins a meeting through the
 * separate, server-validated agent endpoint. Every change here is audit-logged.
 */
class MeetingController extends Controller
{
    /** GET /api/meetings — list with participant counts + live status. */
    public function index(Request $request): JsonResponse
    {
        $meetings = Meeting::query()
            ->withCount('participants')
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
                'participant_count' => $m->participants_count,
                'notes'             => $m->notes,
                'created_by'        => $m->creator?->name,
                'created_at'        => $m->created_at?->toDateTimeString(),
            ]);

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

    /** GET /api/meetings/{meeting}/participation — scheduled vs actual per participant. */
    public function participation(Request $request, Meeting $meeting): JsonResponse
    {
        $meeting->load('participants.employee:id,employee_code,first_name,last_name');

        $sessions = EmployeeMeetingSession::where('meeting_id', $meeting->id)
            ->get()
            ->groupBy('employee_id');

        $rows = $meeting->participants->map(function ($p) use ($sessions, $meeting) {
            $mine = $sessions->get($p->employee_id) ?? collect();
            $total = (int) $mine->sum('duration_seconds');
            $firstIn = $mine->min('actual_start_at');
            $lastOut = $mine->max('actual_end_at');

            $attended = $mine->isNotEmpty();
            $status = $attended ? 'ATTENDED'
                : ($meeting->end_at->isPast() ? 'ABSENT' : 'PENDING');

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
            'status'            => $this->liveStatus($m),
            'participants'      => $m->participants_count,
            'attended'          => (int) ($sessions[$m->id]->attended ?? 0),
            'scheduled_seconds' => ($m->start_at && $m->end_at) ? (int) $m->end_at->diffInSeconds($m->start_at, true) : null,
            'actual_seconds'    => (int) ($sessions[$m->id]->secs ?? 0),
        ]);

        return response()->json(['data' => $rows]);
    }

    // ---- helpers ----

    private function validateMeeting(Request $request, int $companyId): array
    {
        return $request->validate([
            'title'             => ['required', 'string', 'max:200'],
            'purpose'           => ['nullable', 'string', 'max:2000'],
            'start_at'          => ['required', 'date'],
            'end_at'            => ['required', 'date', 'after:start_at'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'participant_ids'   => ['required', 'array', 'min:1', 'max:1000'],
            'participant_ids.*' => ['integer', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
        ]);
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
        if ($meeting->status === 'CANCELLED') {
            return 'CANCELLED';
        }
        $now = now();
        if ($meeting->end_at->lte($now)) {
            return 'COMPLETED';
        }
        if ($meeting->start_at->lte($now)) {
            return 'IN_PROGRESS';
        }

        return 'SCHEDULED';
    }
}
