<?php

namespace App\Console\Commands;

use App\Models\EmployeeMeetingSession;
use App\Models\Meeting;
use Illuminate\Console\Command;

/**
 * Section 2 — advance meeting statuses (SCHEDULED → IN_PROGRESS → COMPLETED) and
 * auto-close any employee meeting session at the scheduled end time (unless the
 * meeting was extended). Runs every minute so "Meeting" status ends on time even
 * if the employee never presses End.
 */
class CloseMeetings extends Command
{
    protected $signature = 'smartept:close-meetings';

    protected $description = 'Advance meeting statuses and auto-close meeting sessions at the scheduled end';

    public function handle(): int
    {
        $now = now();

        Meeting::withoutGlobalScopes()
            ->where('status', 'SCHEDULED')
            ->where('start_at', '<=', $now)->where('end_at', '>', $now)
            ->update(['status' => 'IN_PROGRESS']);

        Meeting::withoutGlobalScopes()
            ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
            ->where('end_at', '<=', $now)
            ->update(['status' => 'COMPLETED']);

        // Close sessions whose meeting has ended (or was cancelled) at the scheduled end.
        $open = EmployeeMeetingSession::withoutGlobalScopes()
            ->whereNull('actual_end_at')
            ->with('meeting')
            ->get();

        foreach ($open as $s) {
            $m = $s->meeting;
            if (! $m) {
                continue;
            }
            if ($m->end_at->lte($now) || $m->status === 'CANCELLED') {
                $end = $m->status === 'CANCELLED' ? $now : $m->end_at;
                if ($s->actual_start_at && $end->lessThan($s->actual_start_at)) {
                    $end = $s->actual_start_at;
                }
                $s->update([
                    'actual_end_at'    => $end,
                    'duration_seconds' => $s->actual_start_at ? (int) $end->diffInSeconds($s->actual_start_at, true) : 0,
                ]);
            }
        }

        // Also close the authoritative status-timeline MEETING segment at the scheduled
        // end, so the live dashboard stops showing "In Meeting" after a meeting is over even
        // when the employee never pressed Leave (bug: admin saw In Meeting though the meeting
        // had ended and the employee side was already normal).
        \App\Models\StatusTimeline::withoutGlobalScopes()
            ->whereNull('ended_at')
            ->where('state', 'MEETING')
            ->whereNotNull('meeting_id')
            ->get()
            ->each(function ($seg) use ($now) {
                $m = Meeting::withoutGlobalScopes()->find($seg->meeting_id);
                if (! $m) {
                    return;
                }
                if ($m->end_at->lte($now) || $m->status === 'CANCELLED') {
                    $end = $m->status === 'CANCELLED' ? $now : $m->end_at;
                    if ($end->lessThan($seg->started_at)) {
                        $end = $seg->started_at;
                    }
                    $seg->forceFill([
                        'ended_at'         => $end,
                        'duration_seconds' => max(0, $end->getTimestamp() - $seg->started_at->getTimestamp()),
                    ])->save();
                }
            });

        return self::SUCCESS;
    }
}
