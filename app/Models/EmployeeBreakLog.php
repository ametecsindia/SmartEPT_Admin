<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeBreakLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }

    /**
     * 28-Sep-2026 (Ejaz): a door punch-out with NO Break started. GateService creates these
     * itself (source BIOMETRIC, no device); a Break the employee started keeps its device_uuid
     * even after the door confirms it. Reported as Away (Non-Productive), never as Break.
     */
    public function scopeUnannounced($q)
    {
        return $q->where('source', 'BIOMETRIC')->whereNull('device_uuid');
    }
}
