<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = [
        'working_days'     => 'array',
        'crosses_midnight' => 'boolean',
    ];

    public function employees() { return $this->hasMany(Employee::class); }
}
