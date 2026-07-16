<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeAttendanceLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'work_date'         => 'date',
        'check_in_at'       => 'datetime',
        'check_out_at'      => 'datetime',
        'first_activity_at' => 'datetime',
        'last_activity_at'  => 'datetime',
        'metadata'          => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
