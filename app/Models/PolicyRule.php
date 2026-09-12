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
        'protections'  => 'array',
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

    /**
     * Protections block an ACTIVITY inside an application while the application
     * itself keeps running. Orthogonal to `action` and to `status` on purpose:
     * "WhatsApp is allowed, sending files out of it is not" is the requirement,
     * and it cannot be expressed by any value of `action`, whose meanings only
     * apply to a blocked row.
     *
     * Keys are stored in the `protections` JSON column; an absent key is false.
     * The list will grow (microphone, clipboard, printing), which is why the
     * column is JSON and this constant is the single place the set is named.
     */
    public const PROTECTIONS = ['file', 'image', 'camera'];

    /** The protections actually switched on, normalised. @return array<int,string> */
    public function protectionList(): array
    {
        $set = (array) ($this->protections ?? []);

        return array_values(array_filter(
            self::PROTECTIONS,
            static fn (string $k): bool => ! empty($set[$k])
        ));
    }

    public function hasProtection(string $key): bool
    {
        return in_array($key, $this->protectionList(), true);
    }

    public function hasProtections(): bool
    {
        return $this->protectionList() !== [];
    }

    /**
     * True when this rule asks for the whole application to be prevented.
     *
     * The console calls this "Full Block & Close"; the stored value is still
     * CLOSE (applications) or BLOCK (websites), unchanged, so nothing that
     * already reads this column had to be touched.
     */
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

    /**
     * Rows that carry at least one protection, whatever their status.
     *
     * Deliberately NOT filtered by status: an ALLOWED row with File Sharing
     * Block on is the central case, and folding this into scopeEnforcing()
     * would have sent it to the endpoint as something to deny outright —
     * exactly the "protection == kill the app" mistake this feature exists to
     * avoid.
     *
     * SQL narrows to "has a non-empty protections value" and the caller filters
     * precisely in PHP. A rule set is at most a couple of thousand rows, and a
     * portable JSON predicate across MySQL and the SQLite the suite runs on is
     * not worth the fragility.
     */
    public function scopeWithProtections($query)
    {
        return $query->whereNotNull('protections')
                     ->whereNotIn('protections', ['', '[]', '{}', 'null']);
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
