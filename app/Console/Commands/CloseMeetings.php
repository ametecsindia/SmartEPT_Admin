<?php

namespace App\Console\Commands;

use App\Models\EmployeeMeetingSession;
use App\Models\Meeting;
use Illuminate\Console\Command;

/**
 * Section 2 / EPT25-08 — advance meeting statuses WITHOUT auto-completing at the
 * scheduled end. A meeting that runs past its scheduled end stays IN_PROGRESS
 * (overrunning) until the organiser or an admin presses End (MeetingController@end).
 *
 * The ONLY automatic terminal transition here is a long-stop safety net: a meeting
 * nobody ended is auto-closed once it is LONG_STOP_HOURS past its scheduled end, so
 * we never leak "zombie" in-progress meetings / stuck "In Meeting" board rows:
 *   - had real attendance  -> AUTO_CLOSED
 *   - nobody ever joined    -> NO_SHOW
 * Cancelled meetings (manual, before start) are handled by MeetingController@cancel.
 *
 * Runs every minute.
 */
class CloseMeetings extends Command
{
    protected $signature = 'smartept:close-meetings';

    protected $description = 'Advance meeting statuses and long-stop auto-close meetings nobody ended';

    /** Hours past the scheduled end before an un-ended meeting is auto-closed. */
    private const LONG_STOP_HOURS = 4;

    public function handle(): int
    {
        $now = now();

        // Start meetings whose window has opened. An overrunning meeting (end passed,
        // not ended) also stays IN_PROGRESS — we deliberately do NOT flip it to COMPLETED.
        Meeting::withoutGlobalScopes()
            ->where('status', 'SCHEDULED')
            ->where('start_at', '<=', $now)
            ->update(['status' => 'IN_PROGRESS']);

        // Long-stop: only meetings still open LONG_STOP_HOURS past the scheduled end.
        $cutoff = $now->copy()->subHours(self::LONG_STOP_HOURS);

        $stale = Meeting::withoutGlobalScopes()
            ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
            ->where('end_at', '<=', $cutoff)
            ->get();

        foreach ($stale as $m) {
            $hadAttendance = EmployeeMeetingSession::withoutGlobalScopes()
                ->where('meeting_id', $m->id)->exists();

            // Best-estimate actual end when nobody pressed End = the scheduled end.
            $end = $m->end_at ?? $now;

            $m->forceFill([
                'status'        => $hadAttendance ? 'AUTO_CLOSED' : 'NO_SHOW',
                'actual_end_at' => $m->actual_end_at ?? $end,
            ])->save();

            $this->closeOpenArtifacts($m, $end);
        }

        return self::SUCCESS;
    }

    /** Close any still-open meeting sessions + status-timeline MEETING segments at $end. */
    private function closeOpenArtifacts(Meeting $m, \Illuminate\Support\Carbon $end): void
    {
        EmployeeMeetingSession::withoutGlobalScopes()
            ->where('meeting_id', $m->id)
            ->whereNull('actual_end_at')
            ->get()
            ->each(function ($s) use ($end) {
                $e = ($s->actual_start_at && $end->lessThan($s->actual_start_at)) ? $s->actual_start_at : $end;
                $s->update([
                    'actual_end_at'    => $e,
                    'duration_seconds' => $s->actual_start_at ? (int) $e->diffInSeconds($s->actual_start_at, true) : 0,
                ]);
            });

        \App\Models\StatusTimeline::withoutGlobalScopes()
            ->whereNull('ended_at')
            ->where('state', 'MEETING')
            ->where('meeting_id', $m->id)
            ->get()
            ->each(function ($seg) use ($end) {
                $e = $end->lessThan($seg->started_at) ? $seg->started_at : $end;
                $seg->forceFill([
                    'ended_at'         => $e,
                    'duration_seconds' => max(0, $e->getTimestamp() - $seg->started_at->getTimestamp()),
                ])->save();
            });
    }
}
