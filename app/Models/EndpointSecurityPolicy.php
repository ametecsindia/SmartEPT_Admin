<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Endpoint Security: a company's Security Compliance policy (one row per company). */
class EndpointSecurityPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['settings' => 'array'];

    /** The company's settings merged over the defaults; unknown keys are dropped. */
    public static function settingsFor(int $companyId): array
    {
        $defaults = config('endpoint_security.policy_defaults');
        $saved = static::withoutGlobalScopes()->where('company_id', $companyId)->value('settings');
        $saved = is_string($saved) ? (json_decode($saved, true) ?: []) : (array) $saved;

        return array_merge($defaults, array_intersect_key($saved, $defaults));
    }
}
