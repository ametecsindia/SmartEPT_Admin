<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * One endpoint running the enforcement service.
 *
 * Holds its own Sanctum token with the `enforcer` ability, separate from the
 * agent's `agent` token: the service must authenticate at boot with nobody
 * signed in, and it must not inherit an employee's rights just because that
 * employee happens to be logged in.
 */
class EnforcementMachine extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use BelongsToCompany;
    use HasApiTokens;

    protected $guarded = ['id'];

    /**
     * Sanctum resolves a token to its tokenable and then hands it to the guard,
     * which calls getAuthIdentifier() on it. HasApiTokens alone does not provide
     * that — the tokenable has to be Authenticatable — so every enforcer request
     * died with "Call to undefined method getAuthIdentifier()".
     *
     * There is no password and no remember-me here: a machine authenticates by
     * bearer token and nothing else. Naming a remember_token column that does
     * not exist would fail the moment anything tried to write it.
     */
    public function getRememberTokenName()
    {
        // NOT a redeclared $rememberTokenName property — the trait already
        // declares one, and PHP refuses a composition where both do. Override
        // the method, which is the supported way to say "this identity has no
        // remember-me column".
        return null;
    }

    /**
     * A machine is never a super admin.
     *
     * BelongsToCompany asks the authenticated identity this to decide whether to
     * apply the tenant scope. It used to assume that identity was always a User.
     */
    public function isSuperAdmin(): bool
    {
        return false;
    }

    protected $casts = [
        'enrolled_at'  => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    /** What a machine can enforce. */
    public const LEVEL_FULL = 'FULL';
    public const LEVEL_REDUCED = 'REDUCED';
    public const LEVEL_NONE = 'NONE';
    public const LEVELS = [self::LEVEL_FULL, self::LEVEL_REDUCED, self::LEVEL_NONE];

    /** What it is achieving right now. */
    public const HEALTH = ['PROTECTED', 'AT_RISK', 'UNKNOWN'];

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Can this endpoint be trusted to enforce what the console shows?
     *
     * Deliberately strict. A machine that cannot enforce, or that is applying a
     * policy without actually preventing anything, must never read as protected
     * — that conflation is what cost the collection agency their bank.
     */
    public function isProtected(): bool
    {
        return $this->isActive()
            && $this->enforcement_level !== self::LEVEL_NONE
            && $this->enforcement_health === 'PROTECTED';
    }

    public function device()
    {
        return $this->belongsTo(EmployeeDevice::class, 'device_uuid', 'device_uuid');
    }
}
