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
}
