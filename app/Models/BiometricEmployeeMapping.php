<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BiometricEmployeeMapping extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
