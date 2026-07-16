<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeComplianceEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'screenshot_captured' => 'boolean',
        'started_at'          => 'datetime',
        'resolved_at'         => 'datetime',
        'metadata'            => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
