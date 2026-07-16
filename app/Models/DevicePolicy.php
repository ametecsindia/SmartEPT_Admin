<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DevicePolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'require_antivirus'       => 'boolean',
        'require_firewall'        => 'boolean',
        'require_disk_encryption' => 'boolean',
        'blocked_software'        => 'array',
        'settings'                => 'array',
    ];
}
