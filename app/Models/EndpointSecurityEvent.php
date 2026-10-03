<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Endpoint Security: a meaningful Defender event, or a server-side alert open/resolve. */
class EndpointSecurityEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => 'datetime'];
}
