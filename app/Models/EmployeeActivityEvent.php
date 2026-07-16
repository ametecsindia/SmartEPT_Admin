<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeActivityEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at'        => 'datetime',
        'ended_at'          => 'datetime',
        'keyboard_activity' => 'boolean',
        'mouse_activity'    => 'boolean',
        'metadata'          => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
