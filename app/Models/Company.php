<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'storage_settings'    => 'array',
        'data_retention_days' => 'integer',
    ];

    public function branches()    { return $this->hasMany(Branch::class); }
    public function departments()  { return $this->hasMany(Department::class); }
    public function teams()        { return $this->hasMany(Team::class); }
    public function designations() { return $this->hasMany(Designation::class); }
    public function shifts()       { return $this->hasMany(Shift::class); }
    public function employees()    { return $this->hasMany(Employee::class); }
    public function users()        { return $this->hasMany(User::class); }
}
