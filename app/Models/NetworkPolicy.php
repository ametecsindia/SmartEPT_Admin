<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class NetworkPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'allowed_public_ips'   => 'array',
        'allowed_lan_ranges'   => 'array',
        'allowed_ssids'        => 'array',
        'allowed_vpn_networks' => 'array',
        'remote_work_allowed'  => 'boolean',
        'block_unknown_network' => 'boolean',
    ];
}
