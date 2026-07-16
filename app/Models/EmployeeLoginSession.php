<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeLoginSession extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'login_at'  => 'datetime',
        'logout_at' => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
