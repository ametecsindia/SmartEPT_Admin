<?php

namespace App\Services;

use App\Models\StatusTimeline;
use RuntimeException;

/**
 * Thrown by {@see StatusService::transition()} when a MANUAL break/meeting is started
 * while a DIFFERENT break/meeting is already open (locked decision D1 — the employee
 * must end the current one first). Callers translate this into an HTTP 409 carrying the
 * currently-open segment so the agent can show "End your current break first".
 */
class ConflictingStatusException extends RuntimeException
{
    public function __construct(public readonly StatusTimeline $open)
    {
        parent::__construct('A different break or meeting is already in progress.');
    }

    /** The shape returned to the agent inside the 409 body. */
    public function activePayload(): array
    {
        return [
            'state'      => $this->open->state,
            'started_at' => optional($this->open->started_at)->toIso8601String(),
            'meeting_id' => $this->open->meeting_id,
        ];
    }
}
