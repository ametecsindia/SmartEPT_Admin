<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PolicyAssignment extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];
}
