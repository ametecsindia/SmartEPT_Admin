<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WebcamPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'presence_enabled'          => 'boolean',
        'photo_enabled'             => 'boolean',
        'photo_on_violation'        => 'boolean',
        'photo_on_attendance'       => 'boolean',
        'face_confidence_threshold' => 'float',
    ];
}
