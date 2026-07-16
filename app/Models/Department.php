<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function teams()  { return $this->hasMany(Team::class); }
    public function employees() { return $this->hasMany(Employee::class); }
}
