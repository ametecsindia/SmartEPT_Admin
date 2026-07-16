<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeDevice extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['device_token_hash'];

    protected $casts = [
        'camera_available'     => 'boolean',
        'microphone_available' => 'boolean',
        'last_heartbeat_at'    => 'datetime',
        'last_sync_at'         => 'datetime',
        'registered_at'        => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
