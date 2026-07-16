<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Designation extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    public function employees() { return $this->hasMany(Employee::class); }
}
