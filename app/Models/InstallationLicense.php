<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A licence record cached from SmartEPT Central.
 *
 * Two scopes (12-Aug-2026, per-tenant licensing on the shared cloud install):
 *   - company_id NULL  → the ONE install-level licence (client-hosted servers,
 *     and the fallback for non-SaaS companies on any install). Unchanged behaviour.
 *   - company_id set   → a cloud tenant's OWN licence on the shared install
 *     (companies with deployment_model = AMETECS_SAAS). One row per tenant,
 *     its own key, seats, expiry and daily phone-home.
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

    protected $fillable = ['company_id', 'license_key', 'status', 'bundle', 'last_checked_at', 'unreachable_since', 'last_error', 'key_saved_at'];

    protected $casts = [
        'bundle' => 'array',
        'last_checked_at' => 'datetime',
        'unreachable_since' => 'datetime',
        // When the key was ENTERED. Never moved by a validation attempt — see
        // unverifiedGraceOpen().
        'key_saved_at' => 'datetime',
    ];

    /** Install-level singleton (company_id NULL) — creates the empty row on first use. */
    public static function current(): self
    {
        return static::query()->whereNull('company_id')->orderBy('id')->first()
            ?? static::query()->create([]);
    }

    /** A cloud tenant's own licence row — created empty on first use (evaluation clock starts). */
    public static function forCompany(int $companyId): self
    {
        return static::query()->firstOrCreate(['company_id' => $companyId]);
    }

    /**
     * The licence that governs a company: AMETECS_SAAS tenants carry their OWN
     * licence; every other deployment (and no-company contexts) uses the
     * install-level row — exactly the pre-existing behaviour.
     */
    public static function governing(?Company $company): self
    {
        return ($company && $company->deployment_model === 'AMETECS_SAAS')
            ? static::forCompany($company->id)
            : static::current();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function configured(): bool
    {
        return ! empty($this->license_key);
    }

    /**
     * Was this licence established from a signed .lic FILE rather than from Central?
     * LicenseFile::apply() stamps bundle.source = 'file'. A file licence carries its
     * own RSA signature and machine binding, so Central adds nothing to it — and must
     * never be allowed to overrule it (see LicenseClient).
     */
    public function fromFile(): bool
    {
        return ($this->bundle['source'] ?? null) === 'file';
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

    /** When the no-key evaluation window closes (7 days from first boot / tenant creation). */
    public function evaluationEndsAt(): Carbon
    {
        return ($this->created_at ?? now())->copy()->addDays(self::EVALUATION_DAYS)->endOfDay();
    }

    /**
     * How long an as-yet-unconfirmed key stays usable, measured from when it was
     * SAVED — not from when we last tried to check it.
     *
     * This window used to be anchored on last_checked_at, which the unreachable
     * branch of LicenseClient::validate() sets to now() on EVERY failure. On an
     * air-gapped server the nightly phone-home therefore failed, bumped the
     * anchor, and reopened the window — for ever. Typing any string into the
     * Licence screen bought permanent, uncapped access, which is exactly the
     * bypass the 19-Aug-2026 fix was written to close.
     *
     * The existing regression test passed because it never runs a phone-home
     * between its two assertions. Production does, nightly.
     *
     * key_saved_at is written only where a key is actually entered, so nothing
     * a failed validation does can move it. The fallbacks are for rows written
     * before the column existed; updated_at is deliberately LAST, because any
     * save() moves it.
     */
    private function unverifiedGraceOpen(): bool
    {
        $since = $this->key_saved_at ?? $this->created_at ?? $this->updated_at ?? now();

        return now()->lte($since->copy()->addDays(self::EVALUATION_DAYS)->endOfDay());
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
            // A key was saved but Central has never confirmed it — Central was
            // unreachable at the moment it was entered. That is a genuine, transient
            // cloud state, so it is allowed… but only for the same 7 days the no-key
            // evaluation gets. Left open-ended this WAS a free licence: on an air-gapped
            // server, typing any string into the Licence screen granted permanent,
            // uncapped access, because nothing would ever move the status off
            // 'unconfigured'. (Ejaz, 19-Aug-2026.)
            //
            // A properly licensed on-premise install is NOT affected: a signed .lic
            // makes the status 'active' via LicenseFile::apply(), never 'unconfigured'.
            'unconfigured' => $this->unverifiedGraceOpen(),
            default => false,
        };
    }
}
