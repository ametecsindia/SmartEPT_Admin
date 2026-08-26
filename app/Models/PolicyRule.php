<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One item on the Rules screen, with its own action.
 *
 * Before this existed, action_on_blocked sat on the policy, so every blocked
 * app in a tenant shared one action. That is why the console could offer
 * "close the app" and nothing could ever be armed selectively.
 */
class PolicyRule extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'identifiers'  => 'array',
        'confirmed_at' => 'datetime',
    ];

    /**
     * The console stores a tick, the table stores a timestamp. Without this the
     * "I understand this also stops people working" box came back unticked on
     * every reload, and the row silently lost its confirmation.
     */
    protected $appends = ['confirmed'];

    public function getConfirmedAttribute(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** Actions that only inform. The agent handles these; nothing is prevented. */
    public const SOFT_ACTIONS = ['LOG', 'WARN', 'NOTIFY', 'SCREENSHOT'];

    /** Actions that actually prevent something. These reach the enforcement service. */
    public const HARD_ACTIONS = ['CLOSE', 'BLOCK'];

    public const ACTIONS = ['LOG', 'WARN', 'NOTIFY', 'SCREENSHOT', 'CLOSE', 'BLOCK'];

    public const STATUSES = ['TRACKED', 'ALLOWED', 'BLOCKED', 'VIOLATION'];

    public const TYPES = ['APPLICATION', 'WEBSITE'];

    /** True when this rule asks for real prevention rather than a warning. */
    public function isEnforcing(): bool
    {
        return in_array(strtoupper((string) $this->action), self::HARD_ACTIONS, true)
            && in_array(strtoupper((string) $this->status), ['BLOCKED', 'VIOLATION'], true);
    }

    public function scopeEnforcing($query)
    {
        return $query->whereIn('action', self::HARD_ACTIONS)
                     ->whereIn('status', ['BLOCKED', 'VIOLATION']);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('policy_type', strtoupper($type));
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
