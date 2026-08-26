<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a tenant's enforcement is off, learning, or actually blocking.
 *
 * A company with no row is OFF. That is deliberate: an upgrade must never turn
 * enforcement on for anybody (decision 4).
 */
class EnforcementState extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'audit_started_at' => 'datetime',
        'cleared_at'       => 'datetime',
        'disabled_at'      => 'datetime',
    ];

    /** Nothing is prevented. The agent warns, exactly as it always has. */
    public const OFF = 'OFF';

    /** Rules are evaluated and reported. Nothing is blocked. Every tenant starts here. */
    public const AUDIT = 'AUDIT';

    /** Rules actually block. Only reachable through a cleared audit report. */
    public const ENFORCE = 'ENFORCE';

    public const MODES = [self::OFF, self::AUDIT, self::ENFORCE];

    public static function forCompany(int $companyId): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId],
            ['mode' => self::OFF, 'policy_version' => 1]
        );
    }

    public function isEnforcing(): bool
    {
        return $this->mode === self::ENFORCE;
    }

    /**
     * Minimum learning period, in minutes, before a promotion is credible.
     *
     * Configurable rather than a constant: what is long enough differs between a
     * pilot on one PC and a bank with three shifts. It was three days; it is now
     * whatever `smartept.min_audit_minutes` says, defaulting to 20.
     *
     * Whoever shortens it should know what they are trading away — a clean
     * report after twenty minutes mostly means nobody has opened their usual
     * programs yet, which is not the same as nothing being at risk.
     */
    public static function minAuditMinutes(): int
    {
        return max(0, (int) config('smartept.min_audit_minutes', 20));
    }

    /** Minutes elapsed since learning began. Zero when it has not started. */
    public function auditMinutes(): float
    {
        if (! $this->audit_started_at) {
            return 0.0;
        }

        return round(max(0, $this->audit_started_at->diffInSeconds(now())) / 60, 1);
    }

    /** Kept for the report payload and anything already reading days. */
    public function auditDays(): float
    {
        return round($this->auditMinutes() / 1440, 1);
    }

    /** How the remaining wait should be described to a human. */
    public function auditRemainingLabel(): string
    {
        $left = self::minAuditMinutes() - $this->auditMinutes();
        if ($left <= 0) {
            return '';
        }
        if ($left < 90) {
            return sprintf('%d more minute(s)', (int) ceil($left));
        }
        if ($left < 2880) {
            return sprintf('%.1f more hour(s)', $left / 60);
        }

        return sprintf('%.1f more day(s)', $left / 1440);
    }
}
