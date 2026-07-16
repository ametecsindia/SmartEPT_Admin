<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StorageFile extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'encrypted'  => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
