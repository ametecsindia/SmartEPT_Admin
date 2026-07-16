<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LocalSyncBatch extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'processed'   => 'boolean',
        'received_at' => 'datetime',
    ];
}
