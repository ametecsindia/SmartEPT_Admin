<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeDailySummary extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'work_date'          => 'date',
        'first_login_at'     => 'datetime',
        'last_logout_at'     => 'datetime',
        'productivity_score' => 'float',
        'compliance_score'   => 'float',
        'metadata'           => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
