<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class UsbPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['allowed_device_classes' => 'array'];
}
