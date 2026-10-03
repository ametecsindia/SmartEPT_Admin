<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Endpoint Security: one remote Defender action. The endpoint only ever receives the
 * TYPE (an enum) and, for a custom scan, a validated path — never a command string.
 */
class EndpointSecurityCommand extends Model
{
    use BelongsToCompany;

    public const TYPES = ['AV_STATUS_REFRESH', 'AV_QUICK_SCAN', 'AV_FULL_SCAN', 'AV_CUSTOM_SCAN', 'AV_SIGNATURE_UPDATE'];

    public const OPEN = ['queued', 'received', 'running'];

    protected $guarded = ['id'];

    protected $casts = [
        'parameters' => 'array',
        'requested_at' => 'datetime', 'expires_at' => 'datetime', 'received_at' => 'datetime',
        'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function machine()
    {
        return $this->belongsTo(EnforcementMachine::class, 'enforcement_machine_id');
    }
}
