<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebcamPhotoAccessLog extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['viewed_at' => 'datetime'];
}
