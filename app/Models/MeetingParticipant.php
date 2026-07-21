<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MeetingParticipant extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
}
