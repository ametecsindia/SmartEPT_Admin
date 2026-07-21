<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BiometricDevice extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /** The cloud API password never leaves the server. */
    protected $hidden = ['api_password'];

    protected $casts = [
        'last_sync_at'     => 'datetime',
        'last_sync_ok_at'  => 'datetime',
        'next_sync_at'     => 'datetime',
        'sync_enabled'     => 'boolean',
        'api_password'     => 'encrypted',
        'sync_times'       => 'array',
        'last_sync_counts' => 'array',
    ];

    public function logs() { return $this->hasMany(BiometricLog::class); }
}
