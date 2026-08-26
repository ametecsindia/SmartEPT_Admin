<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Multi-tenant scoping. Any model using this trait is automatically filtered to the
 * authenticated user's company, and new rows inherit that company_id. Super Admins
 * (no company boundary) and unauthenticated contexts (seeding, console) are unscoped.
 */
trait BelongsToCompany
{
    /**
     * True while this model is inside Auth::user(), so the scope cannot re-enter.
     *
     * A trait's static property is separate per using class, which is exactly
     * what is wanted: a scope on one model must not be switched off because a
     * different model is being resolved.
     */
    protected static bool $resolvingIdentity = false;

    /**
     * The signed-in identity, or null while one is still being worked out.
     *
     * The loop this prevents:
     *
     *   query EnforcementMachine -> company scope -> Auth::user()
     *     -> Sanctum resolves the device token
     *     -> loads the tokenable, which IS an EnforcementMachine
     *     -> query EnforcementMachine -> company scope -> Auth::user() -> ...
     *
     * The guard memoises the resolved user only after resolution finishes, so a
     * re-entrant call during resolution never terminates. It ate 2 GB and died
     * with a fatal, which is not a stack overflow anyone can read.
     *
     * Returning null here means the lookup that resolves the identity runs
     * unscoped. That is correct and not a tenancy hole: it is a lookup by token
     * id or by primary key, both already unique across tenants, and every query
     * after resolution is scoped normally.
     */
    protected static function currentIdentity()
    {
        if (static::$resolvingIdentity) {
            return null;
        }

        static::$resolvingIdentity = true;
        try {
            return Auth::user();
        } finally {
            static::$resolvingIdentity = false;
        }
    }

    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            // The authenticated identity is not always a User any more — the
            // enforcement service authenticates as an EnforcementMachine. Ask
            // only what the contract can answer, or a machine-authenticated
            // request dies inside a global scope with a method-not-found.
            $user = static::currentIdentity();
            if (! $user || ! $user->company_id) {
                // Unauthenticated: seeding, the console, and the moment during
                // token resolution before an identity exists. Unscoped, as it
                // always was — and checked FIRST, because method_exists(null)
                // is a TypeError, not a false.
                return;
            }
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return;
            }

            $builder->where($builder->getModel()->getTable() . '.company_id', $user->company_id);
        });

        static::creating(function (Model $model) {
            $user = static::currentIdentity();
            if ($user && $user->company_id && empty($model->company_id)) {
                $model->company_id = $user->company_id;
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(\App\Models\Company::class);
    }
}
