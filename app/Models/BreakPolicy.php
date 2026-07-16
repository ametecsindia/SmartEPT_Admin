<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BreakPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'break_types'           => 'array',
        'auto_detect_from_idle' => 'boolean',
        'requires_approval'     => 'boolean',
    ];
}
