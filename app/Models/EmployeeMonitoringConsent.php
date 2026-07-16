<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeMonitoringConsent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'acknowledged'    => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
