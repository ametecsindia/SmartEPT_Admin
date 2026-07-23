<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One segment of the authoritative status timeline (QA Phase 1). Written only through
 * {@see \App\Services\StatusService}; never insert/update this table directly elsewhere,
 * or the "exactly one open segment per employee" invariant is lost.
 */
class StatusTimeline extends Model
{
    use BelongsToCompany;

    protected $table = 'status_timeline';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    /** Break states (never counts as productive; excludes MEETING, which is productive). */
    public const BREAK_STATES = ['TEA_BREAK', 'LUNCH_BREAK', 'OTHER_BREAK'];

    public function employee() { return $this->belongsTo(Employee::class); }

    public function meeting() { return $this->belongsTo(Meeting::class); }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public function isBreak(): bool
    {
        return in_array($this->state, self::BREAK_STATES, true);
    }
}
