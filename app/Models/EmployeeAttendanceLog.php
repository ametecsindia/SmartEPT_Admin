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
        // 11-Aug-2026: these three were added 23-Jul (QA A2) but never cast —
        // AttendanceDerivation got raw strings and crashed with
        // "Call to a member function lessThan() on string" for every agent-edge day.
        'first_login_at'    => 'datetime',
        'last_login_at'     => 'datetime',
        'final_logout_at'   => 'datetime',
        'first_activity_at' => 'datetime',
        'last_activity_at'  => 'datetime',
        'metadata'          => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
