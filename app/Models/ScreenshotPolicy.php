<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ScreenshotPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'enabled'            => 'boolean',
        'random_enabled'     => 'boolean',
        'on_violation'       => 'boolean',
        'on_blocked_website' => 'boolean',
        'on_blocked_app'     => 'boolean',
        'during_idle'        => 'boolean',
        'active_work_only'   => 'boolean',
        'blur_sensitive'     => 'boolean',
        'excluded_apps'      => 'array',
        'excluded_websites'  => 'array',
    ];
}
