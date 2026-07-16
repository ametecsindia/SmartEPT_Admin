<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompliancePolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'settings'  => 'array',
        'is_active' => 'boolean',
    ];
}
