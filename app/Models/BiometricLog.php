<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BiometricLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'punched_at' => 'datetime',
        'processed'  => 'boolean',
        'metadata'   => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function device()   { return $this->belongsTo(BiometricDevice::class, 'biometric_device_id'); }
}
