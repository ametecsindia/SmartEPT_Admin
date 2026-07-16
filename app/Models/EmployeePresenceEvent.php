<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeePresenceEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'confidence_score' => 'float',
        'started_at'       => 'datetime',
        'ended_at'         => 'datetime',
        'metadata'         => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
