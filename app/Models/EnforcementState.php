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

    /**
     * Rules are evaluated and reported. Nothing is blocked.
     *
     * The learning period. Unreachable on an installation where
     * `smartept.enforcement_learning_enabled` is false — which is every client package since
     * 27-Aug-2026. The constant stays because older rows still hold the value; see
     * effectiveMode() for how one is read.
     */
    public const AUDIT = 'AUDIT';

    /** Rules actually block. Only reachable through a cleared audit report. */
    public const ENFORCE = 'ENFORCE';

    public const MODES = [self::OFF, self::AUDIT, self::ENFORCE];

    /**
     * The company's enforcement row, created on first touch.
     *
     * 26-Aug-2026 (Ejaz): a client package must arrive with enforcement already ON — "no
     * learning again on client side". The catalogue work is done here and ships with the
     * package, so repeating it per client is delay with no discovery in it.
     *
     * The starting mode therefore comes from config rather than being hard-wired to OFF. It
     * still DEFAULTS to OFF, so an upgrade of an existing installation can never switch
     * enforcement on for anybody (decision 4) — only a fresh install whose .env says
     * SMARTEPT_ENFORCEMENT_DEFAULT=ENFORCE starts armed, and only the first time this row is
     * created.
     *
     * A row created straight into ENFORCE is stamped `cleared_report_id = 'PRECONFIGURED'`,
     * never a fake report id. The console must be able to show, truthfully, that this tenant
     * was shipped armed rather than promoted by a clean audit — those are different claims and
     * conflating them is exactly the kind of "reports applied, blocks nothing" lie this whole
     * product exists to avoid.
     */
    public static function forCompany(int $companyId): self
    {
        $mode = strtoupper(trim((string) config('smartept.enforcement_default_mode', self::OFF)));
        if (! in_array($mode, self::MODES, true)) {
            $mode = self::OFF;
        }

        // An installation with no learning period cannot start one, however its .env is
        // written. OFF rather than ENFORCE: a value we did not recognise must never be the
        // thing that arms an estate.
        if ($mode === self::AUDIT && ! self::learningEnabled()) {
            $mode = self::OFF;
        }

        $attributes = ['mode' => $mode, 'policy_version' => 1];
        if ($mode === self::ENFORCE) {
            $attributes['cleared_report_id'] = 'PRECONFIGURED';
            $attributes['cleared_at'] = now();
        }

        return static::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId], $attributes);
    }

    public function isEnforcing(): bool
    {
        return $this->mode === self::ENFORCE;
    }

    /**
     * Does the LEARNING period exist on this installation at all?
     *
     * 27-Aug-2026 (Ejaz): "there should be no learning mechanism in the client." False on every
     * client package. See config/smartept.php for what that trades away.
     */
    public static function learningEnabled(): bool
    {
        return (bool) config('smartept.enforcement_learning_enabled', false);
    }

    /**
     * The mode to ACT on, as opposed to the mode stored in the row.
     *
     * They differ in exactly one case: the row says AUDIT on an installation where learning
     * has been removed. That happens to a site upgraded from an older build, and it is the one
     * state the two-state console cannot draw.
     *
     * The answer is OFF, and it is the honest one rather than a convenient one:
     *
     *   - AUDIT already prevents nothing, so calling it OFF changes what an employee
     *     experiences by exactly zero;
     *   - AUDIT makes every endpoint collect and upload would-have-blocked events, which IS
     *     the learning mechanism. Answering AUDIT to an endpoint on a client site would
     *     restart the very thing that was removed;
     *   - the alternative — quietly reading AUDIT as ENFORCE — would start blocking on an
     *     estate during an upgrade, with nobody having pressed anything (decision 4).
     *
     * So a site left mid-learning lands on OFF, the console shows one button, and an
     * administrator arms it deliberately. The stored value is never rewritten behind their
     * back; only what we DO with it changes.
     */
    public function effectiveMode(): string
    {
        if ($this->mode === self::AUDIT && ! self::learningEnabled()) {
            return self::OFF;
        }

        return (string) $this->mode;
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
