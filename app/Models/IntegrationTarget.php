<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationTarget extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['events' => 'array', 'active' => 'boolean', 'last_pushed_at' => 'datetime', 'secret' => 'encrypted'];
    protected $hidden = ['secret'];

    public function company() { return $this->belongsTo(Company::class); }
}
