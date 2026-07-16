<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    public function department() { return $this->belongsTo(Department::class); }
    public function employees()  { return $this->hasMany(Employee::class); }
    public function manager()    { return $this->belongsTo(User::class, 'manager_user_id'); }
    public function teamLeader()  { return $this->belongsTo(User::class, 'team_leader_user_id'); }
}
