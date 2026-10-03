<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Endpoint Security: the LATEST security state of one machine (one row, upserted). */
class EndpointSecurityStatus extends Model
{
    use BelongsToCompany;

    protected $table = 'endpoint_security_status';

    protected $guarded = ['id'];

    protected $casts = [
        'installed_providers' => 'array', 'errors' => 'array', 'compliance_issues' => 'array', 'open_alerts' => 'array',
        'antivirus_installed' => 'boolean', 'antivirus_enabled' => 'boolean', 'realtime_enabled' => 'boolean',
        'behavior_enabled' => 'boolean', 'ioav_enabled' => 'boolean', 'signature_outdated' => 'boolean',
        'firewall_domain' => 'boolean', 'firewall_private' => 'boolean', 'firewall_public' => 'boolean',
        'signature_updated_at' => 'datetime', 'last_quick_scan_at' => 'datetime', 'last_full_scan_at' => 'datetime',
        'checked_at' => 'datetime', 'received_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(EnforcementMachine::class, 'enforcement_machine_id');
    }
}
