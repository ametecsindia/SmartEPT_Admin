<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The one licence record for this SmartEPT installation (single row).
 * The cached `bundle` is the entitlement payload issued by SmartEPT Central.
 */
class InstallationLicense extends Model
{
    /**
     * Ejaz's rule (16-Jul): the free/trial period is 7 days — after that,
     * an install with no valid licence key blocks agent syncing completely.
     * The console stays reachable so the admin can enter the key.
     */
    public const EVALUATION_DAYS = 7;

    protected $table = 'installation_licenses';

    protected $fillable = ['license_key', 'status', 'bundle', 'last_checked_at', 'unreachable_since', 'last_error'];

    protected $casts = [
        'bundle' => 'array',
        'last_checked_at' => 'datetime',
        'unreachable_since' => 'datetime',
    ];

    /** Singleton accessor — creates the empty row on first use. */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([]);
    }

    public function configured(): bool
    {
        return ! empty($this->license_key);
    }

    public function deviceLimit(): ?int
    {
        return isset($this->bundle['device_limit']) ? (int) $this->bundle['device_limit'] : null;
    }

    public function planCode(): ?string
    {
        return $this->bundle['plan'] ?? null;
    }

    public function companyName(): ?string
    {
        return $this->bundle['company'] ?? null;
    }

    public function expiresAt(): ?Carbon
    {
        return isset($this->bundle['expires_at']) && $this->bundle['expires_at']
            ? Carbon::parse($this->bundle['expires_at'])->endOfDay()
            : null;
    }

    public function graceDays(): int
    {
        return (int) ($this->bundle['grace_days'] ?? 7);
    }

    /** Still inside expiry + grace window (true when perpetual / no expiry). */
    public function withinGrace(): bool
    {
        $expires = $this->expiresAt();

        return $expires === null || now()->lte($expires->copy()->addDays($this->graceDays()));
    }

    /** When the no-key evaluation window closes (7 days from first boot). */
    public function evaluationEndsAt(): Carbon
    {
        return ($this->created_at ?? now())->copy()->addDays(self::EVALUATION_DAYS)->endOfDay();
    }

    public function evaluationDaysLeft(): int
    {
        return max(0, (int) now()->startOfDay()->diffInDays($this->evaluationEndsAt(), false));
    }

    /**
     * May agents keep syncing?
     * - No key configured → only inside the 7-day evaluation window, then BLOCKED.
     * - active → yes. expired → only inside the grace window (trials have 0 grace).
     * - revoked / suspended / unknown_key / server_mismatch → no.
     * - Central unreachable → last known verdict stands (availability first).
     */
    public function operational(): bool
    {
        if (! $this->configured()) {
            return now()->lte($this->evaluationEndsAt());
        }

        return match ($this->status) {
            'active' => true,
            'expired' => $this->withinGrace(),
            'unconfigured' => true, // key saved but never validated yet (Central unreachable)
            default => false,
        };
    }
}
