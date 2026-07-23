<?php

namespace App\Services;

use App\Models\StatusTimeline;

/**
 * Outcome of a {@see StatusService::transition()} call.
 *
 *  - changed  = a new open segment was opened (a real transition happened).
 *  - deduped  = the call matched an existing event_uuid; nothing was written.
 *  - otherwise (neither) the request was a no-op against the current open segment
 *    (same state, or an ambient ACTIVE/IDLE that must not clobber a manual break).
 *
 * `segment` is always the employee's resulting OPEN segment.
 */
class StatusResult
{
    private function __construct(
        public readonly StatusTimeline $segment,
        public readonly bool $changed,
        public readonly bool $deduped,
    ) {}

    public static function changed(StatusTimeline $segment): self
    {
        return new self($segment, true, false);
    }

    public static function unchanged(StatusTimeline $segment): self
    {
        return new self($segment, false, false);
    }

    public static function deduped(StatusTimeline $segment): self
    {
        return new self($segment, false, true);
    }

    public function state(): string
    {
        return $this->segment->state;
    }
}
