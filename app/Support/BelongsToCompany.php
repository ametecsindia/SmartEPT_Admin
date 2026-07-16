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
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $user = Auth::user();
            if ($user && $user->company_id && ! $user->isSuperAdmin()) {
                $builder->where($builder->getModel()->getTable() . '.company_id', $user->company_id);
            }
        });

        static::creating(function (Model $model) {
            $user = Auth::user();
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
