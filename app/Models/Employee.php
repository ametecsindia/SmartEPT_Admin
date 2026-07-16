<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'date_of_joining'   => 'date',
        'date_of_relieving' => 'date',
    ];

    public function branch()      { return $this->belongsTo(Branch::class); }
    public function department()   { return $this->belongsTo(Department::class); }
    public function team()         { return $this->belongsTo(Team::class); }
    public function designation()  { return $this->belongsTo(Designation::class); }
    public function shift()        { return $this->belongsTo(Shift::class); }
    public function manager()      { return $this->belongsTo(User::class, 'manager_user_id'); }
    public function user()         { return $this->belongsTo(User::class); }
    public function devices()      { return $this->hasMany(EmployeeDevice::class); }
    public function consents()     { return $this->hasMany(EmployeeMonitoringConsent::class); }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . (string) $this->last_name);
    }
}
