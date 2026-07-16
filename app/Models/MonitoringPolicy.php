<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MonitoringPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'tracking_enabled'                => 'boolean',
        'working_hours_only'              => 'boolean',
        'app_usage_enabled'               => 'boolean',
        'website_usage_enabled'           => 'boolean',
        'network_compliance_enabled'      => 'boolean',
        'usb_tracking_enabled'            => 'boolean',
        'vpn_proxy_detection_enabled'     => 'boolean',
        'remote_access_detection_enabled' => 'boolean',
        'employee_status_visible'         => 'boolean',
        'consent_required'                => 'boolean',
        'is_active'                       => 'boolean',
    ];
}
