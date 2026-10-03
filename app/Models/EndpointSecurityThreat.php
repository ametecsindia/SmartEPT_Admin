<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Endpoint Security: one Defender detection on one machine. Never file contents. */
class EndpointSecurityThreat extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'resources' => 'array', 'active' => 'boolean', 'action_success' => 'boolean',
        'detected_at' => 'datetime', 'status_changed_at' => 'datetime',
    ];
}
