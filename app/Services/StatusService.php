<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\StatusTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * QA Phase 1 — the SINGLE entry point for every employee status transition.
 *
 * The status_timeline table is an ordered stream of non-overlapping segments with exactly
 * ONE open (ended_at IS NULL) segment per employee. Every write goes through transition(),
 * which serialises concurrent calls with a row lock inside a DB transaction so two events
 * arriving together can never open two segments. This is what makes "what is this person
 * doing right now" a single authoritative answer instead of a guess raced across four
 * legacy tables.
 *
 * Transition rules (handoff §1.2):
 *   1. Idempotency — a repeated event_uuid is a no-op (returns the existing segment).
 *   2. Heartbeat dedupe — same state (and same meeting) as the open segment is a no-op.
 *   3. Ambient guard — an ACTIVE/IDLE coming from background activity must NOT clobber an
 *      open manual break/meeting; only an explicit resume (break/meeting END) or a forced
 *      idle (lock/display-off/suspend) may.
 *   4. Exclusivity (D1) — a MANUAL break/meeting while a DIFFERENT break/meeting is open
 *      throws ConflictingStatusException (caller → HTTP 409). BIOMETRIC/SYSTEM transitions
 *      still close-then-open (the door may switch you).
 *   5. Forced idle overrides MEETING but respects a manual break (you are still on lunch
 *      even if the screen locks).
 */
class StatusService
{
    /** Non-productive break segments (MEETING is productive and excluded). */
    public const BREAK_STATES = ['TEA_BREAK', 'LUNCH_BREAK', 'OTHER_BREAK'];

    /** Break + meeting — the "manual, exclusive" states subject to D1. */
    public const BREAK_OR_MEETING = ['TEA_BREAK', 'LUNCH_BREAK', 'OTHER_BREAK', 'MEETING'];

    /** States driven by ambient activity, which must never overwrite a manual break/meeting. */
    public const AMBIENT_STATES = ['ACTIVE', 'IDLE', 'LOGGED_IN'];

    /**
     * Apply a status transition. Safe to call for every ingested event — repeated,
     * out-of-order, or heartbeat-style calls resolve to no-ops rather than duplicates.
     *
     * @param  array{event_uuid?:string,manual?:bool,resume?:bool,force?:bool,source?:string,meeting_id?:int,idle_source?:string,device_uuid?:string}  $opts
     *
     * @throws ConflictingStatusException  on a D1 break/meeting conflict.
     */
    public function transition(Employee $e, string $newState, Carbon $at, array $opts = []): StatusResult
    {
        return DB::transaction(function () use ($e, $newState, $at, $opts) {
            $eventUuid  = $opts['event_uuid'] ?? null;
            $manual     = (bool) ($opts['manual'] ?? false);
            $resume     = (bool) ($opts['resume'] ?? false);
            $force      = (bool) ($opts['force'] ?? false);
            $source     = $opts['source'] ?? 'AGENT';
            $meetingId  = $opts['meeting_id'] ?? null;
            $idleSource = $opts['idle_source'] ?? null;
            $deviceUuid = $opts['device_uuid'] ?? null;

            // (1) Idempotency: an already-seen event does nothing.
            if ($eventUuid) {
                $seen = StatusTimeline::withoutGlobalScopes()->where('event_uuid', $eventUuid)->first();
                if ($seen) {
                    return StatusResult::deduped($seen);
                }
            }

            $current = $this->openSegment($e->id, lock: true);

            if ($current) {
                // (2) Heartbeat dedupe.
                if ($current->state === $newState && (int) $current->meeting_id === (int) $meetingId) {
                    return StatusResult::unchanged($current);
                }

                // (3) Ambient activity must not clobber a manual break/meeting.
                if (in_array($newState, self::AMBIENT_STATES, true)
                    && ! $resume && ! $force
                    && in_array($current->state, self::BREAK_OR_MEETING, true)) {
                    return StatusResult::unchanged($current);
                }

                // (4) Exclusivity D1 — reject a manual switch between different break/meeting.
                if ($manual
                    && in_array($newState, self::BREAK_OR_MEETING, true)
                    && in_array($current->state, self::BREAK_OR_MEETING, true)) {
                    throw new ConflictingStatusException($current);
                }

                // (5) Forced idle overrides MEETING but respects a manual break.
                if ($force && $newState === 'IDLE'
                    && in_array($current->state, self::BREAK_STATES, true)) {
                    return StatusResult::unchanged($current);
                }
            }

            // Close whatever is open (self-heals any stray duplicates), then open the new one.
            $this->closeOpenSegments($e->id, $at);

            $segment = StatusTimeline::create([
                'company_id'       => $e->company_id,
                'employee_id'      => $e->id,
                'device_uuid'      => $deviceUuid,
                'state'            => $newState,
                'started_at'       => $at,
                'ended_at'         => null,
                'duration_seconds' => null,
                'meeting_id'       => $meetingId,
                'source'           => $source,
                'event_uuid'       => $eventUuid,
                'idle_source'      => $idleSource,
            ]);

            return StatusResult::changed($segment);
        });
    }

    /** Explicit "end of break/meeting → back to work". Never clobbers, always resumes. */
    public function resumeActive(Employee $e, Carbon $at, array $opts = []): StatusResult
    {
        return $this->transition($e, 'ACTIVE', $at, array_merge(['resume' => true], $opts));
    }

    /** Lock / display-off / suspend / disconnect. Overrides MEETING, respects a manual break. */
    public function forceIdle(Employee $e, Carbon $at, string $idleSource = 'LOCK', array $opts = []): StatusResult
    {
        return $this->transition($e, 'IDLE', $at, array_merge([
            'force'       => true,
            'idle_source' => $idleSource,
            'source'      => 'SYSTEM',
        ], $opts));
    }

    /** Logout / day close — every open segment is closed; no open segment == OFFLINE. */
    public function closeAll(Employee $e, Carbon $at): void
    {
        $this->closeOpenSegments($e->id, $at);
    }

    /** The employee's current state, or OFFLINE when nothing is open. */
    public function currentState(Employee $e): string
    {
        return $this->openSegment($e->id)?->state ?? 'OFFLINE';
    }

    /**
     * Open segment per employee, for the live dashboard.
     *
     * @param  int[]  $employeeIds
     * @return array<int,array{state:string,started_at:Carbon,meeting_id:int|null,device_uuid:string|null}>
     */
    public function openStatusMap(array $employeeIds): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $map = [];
        StatusTimeline::withoutGlobalScopes()
            ->whereIn('employee_id', $employeeIds)
            ->whereNull('ended_at')
            ->orderBy('started_at')
            ->get()
            ->each(function ($s) use (&$map) {
                // Latest open wins if a stray duplicate somehow exists (ordered ascending).
                $map[$s->employee_id] = [
                    'state'       => $s->state,
                    'started_at'  => $s->started_at,
                    'meeting_id'  => $s->meeting_id,
                    'device_uuid' => $s->device_uuid,
                ];
            });

        return $map;
    }

    /**
     * Today's totals for an employee, in seconds, split by category. Meeting is productive
     * but returned separately; breaks are split Tea/Lunch/Other with a break_total.
     *
     * @return array{active:int,idle:int,break_tea:int,break_lunch:int,break_other:int,break_total:int,meeting:int}
     */
    public function dayTotals(int $employeeId, string $date, ?Carbon $now = null): array
    {
        $now = $now ?? now();

        $t = ['active' => 0, 'idle' => 0, 'break_tea' => 0, 'break_lunch' => 0, 'break_other' => 0, 'meeting' => 0];

        StatusTimeline::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereDate('started_at', $date)
            ->get()
            ->each(function ($s) use (&$t, $now) {
                $dur = $s->duration_seconds;
                if ($dur === null) { // open segment — count elapsed so far
                    $dur = max(0, $now->getTimestamp() - $s->started_at->getTimestamp());
                }
                match ($s->state) {
                    'ACTIVE', 'LOGGED_IN' => $t['active'] += $dur,
                    'IDLE'                => $t['idle'] += $dur,
                    'TEA_BREAK'           => $t['break_tea'] += $dur,
                    'LUNCH_BREAK'         => $t['break_lunch'] += $dur,
                    'OTHER_BREAK'         => $t['break_other'] += $dur,
                    'MEETING'             => $t['meeting'] += $dur,
                    default               => null,
                };
            });

        $t['break_total'] = $t['break_tea'] + $t['break_lunch'] + $t['break_other'];

        return $t;
    }

    /**
     * Self-heal stale open break/meeting segments left open across a day boundary — e.g.
     * an agent killed mid-break leaves an OTHER_BREAK segment open, and the live board then
     * shows the employee "On break" for 16+ hours. Any break/meeting segment that STARTED
     * before $before is closed at the end of the day it started on, so it can never render
     * as "open now". Returns how many were closed.
     *
     * @param  int[]  $employeeIds
     */
    public function closeStaleOpenSegments(array $employeeIds, Carbon $before): int
    {
        if (empty($employeeIds)) {
            return 0;
        }

        $closed = 0;
        StatusTimeline::withoutGlobalScopes()
            ->whereIn('employee_id', $employeeIds)
            ->whereNull('ended_at')
            ->whereIn('state', self::BREAK_OR_MEETING)
            ->where('started_at', '<', $before)
            ->get()
            ->each(function ($s) use (&$closed) {
                $this->closeSegment($s, $s->started_at->copy()->endOfDay());
                $closed++;
            });

        return $closed;
    }

    /** The single open segment for an employee (optionally row-locked for a transition). */
    private function openSegment(int $employeeId, bool $lock = false): ?StatusTimeline
    {
        $q = StatusTimeline::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }

    /** Close every open segment for an employee at $at (duration clamped ≥ 0). */
    private function closeOpenSegments(int $employeeId, Carbon $at): void
    {
        StatusTimeline::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereNull('ended_at')
            ->get()
            ->each(fn ($s) => $this->closeSegment($s, $at));
    }

    private function closeSegment(StatusTimeline $seg, Carbon $at): void
    {
        $secs = $at->getTimestamp() - $seg->started_at->getTimestamp();
        if ($secs < 0) {
            // Out-of-order (delayed) event: never write a negative duration; preserve order.
            $secs = 0;
        }

        $seg->forceFill(['ended_at' => $at, 'duration_seconds' => $secs])->save();
    }
}
