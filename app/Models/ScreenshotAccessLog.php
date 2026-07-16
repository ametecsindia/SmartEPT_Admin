<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenshotAccessLog extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['viewed_at' => 'datetime'];
}
