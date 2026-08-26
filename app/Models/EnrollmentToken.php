<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A one-time secret that lets one installer run enrol one machine.
 *
 * Only the sha256 is stored. The secret is shown once, at mint time, and never
 * again — an enrolment secret that can be read back out of the database is a
 * permanent way onto the client's estate for anyone who reaches it.
 */
class EnrollmentToken extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** How long a freshly minted token stays usable, unless told otherwise. */
    public const DEFAULT_TTL_HOURS = 24;

    /** The longest one may ever live. A key under the mat is worse the longer it sits. */
    public const MAX_TTL_HOURS = 720; // 30 days

    /**
     * Mint a token. Returns the model and the plaintext secret, which the
     * caller must show to the admin immediately — it cannot be recovered.
     *
     * @return array{0:self,1:string}
     */
    public static function mint(int $companyId, ?int $userId, string $label, int $ttlHours, int $maxUses): array
    {
        $ttlHours = max(1, min($ttlHours, self::MAX_TTL_HOURS));
        $maxUses = max(1, min($maxUses, 1000));

        // Prefixed so a leaked string is instantly recognisable in a log or a
        // paste, and so secret scanners can be taught to spot it.
        $secret = 'sept_enrol_' . Str::random(48);

        $token = static::withoutGlobalScopes()->create([
            'company_id'         => $companyId,
            'token_hash'         => hash('sha256', $secret),
            'label'              => $label !== '' ? $label : 'Enrolment token',
            'created_by_user_id' => $userId,
            'expires_at'         => now()->addHours($ttlHours),
            'max_uses'           => $maxUses,
            'uses'               => 0,
        ]);

        return [$token, $secret];
    }

    /** Find a usable token for a presented secret, or null. */
    public static function redeemable(string $secret): ?self
    {
        $secret = trim($secret);
        if ($secret === '') {
            return null;
        }

        // Looked up by hash, so a timing difference on the lookup reveals
        // nothing an attacker did not already supply.
        $token = static::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $secret))
            ->first();

        return ($token && $token->usable()) ? $token : null;
    }

    public function usable(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture()
            && $this->uses < $this->max_uses;
    }

    /** Why this token cannot be used, for an error message. */
    public function unusableReason(): ?string
    {
        if ($this->revoked_at !== null) {
            return 'This enrolment token was revoked.';
        }
        if ($this->expires_at === null || $this->expires_at->isPast()) {
            return 'This enrolment token has expired. Generate a new one from the console.';
        }
        if ($this->uses >= $this->max_uses) {
            return 'This enrolment token has already been used.';
        }

        return null;
    }
}
