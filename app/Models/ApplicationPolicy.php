<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ApplicationPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'allowed_apps' => 'array',
        'blocked_apps' => 'array',
        'categories'   => 'array',
    ];

    /**
     * The per-item rows behind this policy's JSON lists. The Rules screen reads
     * each row's own action from here; without it every row falls back to the
     * policy-level action_on_blocked and a saved "Close / block it" reads back
     * as "Warn" on the next page load.
     */
    public function rules()
    {
        return $this->hasMany(PolicyRule::class, 'policy_id')
            ->where('policy_type', 'APPLICATION');
    }
}
