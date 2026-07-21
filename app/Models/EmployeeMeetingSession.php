<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeMeetingSession extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'actual_start_at' => 'datetime',
        'actual_end_at'   => 'datetime',
    ];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
}
