<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BiometricDevice extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = ['last_sync_at' => 'datetime'];

    public function logs() { return $this->hasMany(BiometricLog::class); }
}
