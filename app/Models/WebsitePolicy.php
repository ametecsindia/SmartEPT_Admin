<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WebsitePolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'allowed_sites'  => 'array',
        'blocked_sites'  => 'array',
        'categories'     => 'array',
        'track_full_url' => 'boolean',
    ];
}
