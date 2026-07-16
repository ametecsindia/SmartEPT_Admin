<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeIdleLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'idle_start' => 'datetime',
        'idle_end'   => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
