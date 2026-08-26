<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One program an endpoint's policy stopped, or would have stopped.
 *
 * Rows are collapsed per (company, device, target) with an occurrence count —
 * an endpoint reports the same blocked program dozens of times a day and
 * storing each one buys nothing.
 *
 * `expected` is the field the whole audit gate turns on. False means the policy
 * would stop something that is not on the tenant's rules, which in practice
 * means a program staff actually use, usually installed under %LOCALAPPDATA%
 * where the strict allow set does not reach.
 */
class EnforcementAuditEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'expected'      => 'boolean',
        'occurrences'   => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'resolved_at'   => 'datetime',
    ];

    /** Audit mode: the policy did not block this, but it would have. */
    public const WOULD_BLOCK = 'WOULD_BLOCK';

    /** Enforcing: the policy actually stopped this. */
    public const BLOCKED = 'BLOCKED';

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /** Everything still holding up a promotion. */
    public function scopeBlockingPromotion($query)
    {
        return $query->where('expected', false)->whereNull('resolved_at');
    }
}
