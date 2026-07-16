<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use BelongsToCompany;

    protected $table = 'company_holidays';

    protected $guarded = ['id'];

    protected $casts = [
        'holiday_date' => 'date',
    ];
}
