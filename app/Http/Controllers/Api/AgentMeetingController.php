<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeMeetingSession;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Section 2 (agent side) — an employee putting themselves into "Meeting" status.
 * The authorization is enforced HERE on the server, never merely by the agent UI:
 * the caller must be a participant, the meeting must not be cancelled, and NOW must
 * fall inside the scheduled window. Meeting time is recorded as its own session
 * (productive, never a break).
 */
class AgentMeetingController extends Controller
{
    use ResolvesAgentContext;

    /** POST /api/agent/meeting-event  { device_uuid, meeting_id, action: START|END } */
    public function event(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid' => ['required', 'string'],
            'meeting_id'  => ['required', 'integer'],
            'action'      => ['required', 'in:START,END'],
        ]);

        $this->agentDevice($request, $employee);

        $meeting = Meeting::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->find($data['meeting_id']);
        abort_if(! $meeting, 404, 'Meeting not found.');

        $isParticipant = MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('employee_id', $employee->id)->exists();
        abort_unless($isParticipant, 403, 'You are not a participant of this meeting.');

        if ($data['action'] === 'START') {
            abort_if($meeting->status === 'CANCELLED', 422, 'This meeting was cancelled.');
            $now = now();
            abort_unless(
                $meeting->start_at->lte($now) && $meeting->end_at->gte($now),
                422, 'This meeting is not active right now.'
            );

            // Idempotent: an already-open session for this meeting is returned as-is.
            $open = EmployeeMeetingSession::where('meeting_id', $meeting->id)
                ->where('employee_id', $employee->id)->whereNull('actual_end_at')->first();
            if ($open) {
                return response()->json(['ok' => true, 'session_id' => $open->id, 'deduped' => true]);
            }

            $session = EmployeeMeetingSession::create([
                'company_id'      => $employee->company_id,
                'meeting_id'      => $meeting->id,
                'employee_id'     => $employee->id,
                'device_uuid'     => $data['device_uuid'],
                'actual_start_at' => $now,
            ]);

            return response()->json(['ok' => true, 'session_id' => $session->id, 'meeting_title' => $meeting->title], 201);
        }

        // END — close any open session(s) for this meeting + employee.
        $now = now();
        $closed = 0;
        EmployeeMeetingSession::where('meeting_id', $meeting->id)
            ->where('employee_id', $employee->id)
            ->whereNull('actual_end_at')->get()
            ->each(function ($s) use ($now, &$closed) {
                $s->update([
                    'actual_end_at'    => $now,
                    'duration_seconds' => $s->actual_start_at ? (int) $now->diffInSeconds($s->actual_start_at, true) : 0,
                ]);
                $closed++;
            });

        return response()->json(['ok' => true, 'closed' => $closed]);
    }
}
