<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeIdleLog;
use App\Services\StatusService;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ActivityController extends Controller
{
    use ResolvesAgentContext;

    /**
     * POST /api/agent/activity-events
     * Accepts a batch of ACTIVE/IDLE events (the agent batches ~1/min).
     */
    public function activity(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'                 => ['required', 'string'],
            'events'                      => ['required', 'array', 'min:1', 'max:500'],
            'events.*.event_type'         => ['required', 'in:ACTIVE,IDLE'],
            'events.*.started_at'         => ['required', 'date'],
            'events.*.ended_at'           => ['nullable', 'date'],
            'events.*.duration_seconds'   => ['nullable', 'integer', 'min:0'],
            'events.*.active_app'         => ['nullable', 'string', 'max:255'],
            'events.*.window_title'       => ['nullable', 'string', 'max:512'],
            'events.*.keyboard_activity'  => ['nullable', 'boolean'],
            'events.*.mouse_activity'     => ['nullable', 'boolean'],
        ]);

        $this->agentDevice($request, $employee);
        $now = now();
        $rows = [];
        $lastActivity = null;

        foreach ($data['events'] as $e) {
            $start = Carbon::parse($e['started_at']);
            $end = isset($e['ended_at']) ? Carbon::parse($e['ended_at']) : null;
            $duration = $e['duration_seconds'] ?? ($end ? (int) $end->diffInSeconds($start, true) : 0);

            $rows[] = [
                'company_id'        => $employee->company_id,
                'employee_id'       => $employee->id,
                'device_uuid'       => $data['device_uuid'],
                'event_type'        => $e['event_type'],
                'started_at'        => $start,
                'ended_at'          => $end,
                'duration_seconds'  => $duration,
                'active_app'        => $e['active_app'] ?? null,
                'window_title'      => $e['window_title'] ?? null,
                'keyboard_activity' => $e['keyboard_activity'] ?? false,
                'mouse_activity'    => $e['mouse_activity'] ?? false,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
            $lastActivity = $end ?? $start;
        }

        EmployeeActivityEvent::insert($rows);

        // QA Phase 1 (dual-write): reflect ACTIVE/IDLE stretches in the status timeline.
        // Consecutive same-type events collapse to one segment (StatusService also dedupes),
        // and an open manual break/meeting is preserved — ambient activity never clobbers it.
        try {
            $status = app(StatusService::class);
            $prevType = null;
            foreach ($data['events'] as $e) {
                if ($e['event_type'] === $prevType) {
                    continue; // collapse repeats within the batch
                }
                $prevType = $e['event_type'];
                $isIdle = $e['event_type'] === 'IDLE';
                $status->transition($employee, $isIdle ? 'IDLE' : 'ACTIVE', Carbon::parse($e['started_at']), [
                    'device_uuid' => $data['device_uuid'],
                    'idle_source' => $isIdle ? 'INACTIVITY' : null,
                ]);
            }
        } catch (\Throwable $ex) {
            Log::warning('StatusService mirror failed on activity events', ['e' => $ex->getMessage()]);
        }

        // Keep the day's attendance "last activity" fresh.
        if ($lastActivity) {
            EmployeeAttendanceLog::where('employee_id', $employee->id)
                ->where('work_date', $lastActivity->toDateString())
                ->where('source', 'CLIENT')
                ->update(['last_activity_at' => $lastActivity]);
        }

        EmployeeDevice::where('device_uuid', $data['device_uuid'])
            ->update(['last_sync_at' => $now, 'current_status' => 'ONLINE']);

        return response()->json(['ok' => true, 'stored' => count($rows)], 202);
    }

    /**
     * POST /api/agent/idle-event
     * A completed idle stretch (start/end).
     */
    public function idle(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'      => ['required', 'string'],
            'idle_start'       => ['required', 'date'],
            'idle_end'         => ['nullable', 'date'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'reason'           => ['nullable', 'in:NO_INPUT,LOCKED,AWAY'],
        ]);

        $this->agentDevice($request, $employee);
        $start = Carbon::parse($data['idle_start']);
        $end = isset($data['idle_end']) ? Carbon::parse($data['idle_end']) : null;

        EmployeeIdleLog::create([
            'company_id'       => $employee->company_id,
            'employee_id'      => $employee->id,
            'device_uuid'      => $data['device_uuid'],
            'idle_start'       => $start,
            'idle_end'         => $end,
            'duration_seconds' => $data['duration_seconds'] ?? ($end ? (int) $end->diffInSeconds($start, true) : 0),
            'reason'           => $data['reason'] ?? 'NO_INPUT',
        ]);

        return response()->json(['ok' => true], 201);
    }
}
