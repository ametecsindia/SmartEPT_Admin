<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'meeting_date' => 'date',
        'start_at'     => 'datetime',
        'end_at'       => 'datetime',
    ];

    public function participants() { return $this->hasMany(MeetingParticipant::class); }
    public function sessions() { return $this->hasMany(EmployeeMeetingSession::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }

    /**
     * Section 2: the meeting an employee may put themselves into RIGHT NOW — they are a
     * participant, the meeting is not cancelled, and now is within the scheduled window.
     * This is the single source of truth the agent's Meeting button is gated on.
     */
    /**
     * Admin #9: a meeting whose reminder is DUE for this employee — they are a
     * participant, it is not cancelled, a reminder lead-time is set, and NOW is inside
     * [start_at - reminder_minutes, start_at) i.e. the meeting is approaching but has
     * not yet started. Once it starts, currentJoinableFor() takes over (the Join button).
     */
    public static function reminderDueFor(Employee $employee): ?self
    {
        $now = now();

        return static::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('status', '!=', 'CANCELLED')
            ->whereNotNull('reminder_minutes')
            ->where('start_at', '>', $now)
            ->whereRaw('DATE_SUB(start_at, INTERVAL reminder_minutes MINUTE) <= ?', [$now])
            ->whereHas('participants', fn ($q) => $q->where('employee_id', $employee->id))
            ->orderBy('start_at')
            ->first();
    }

    public static function currentJoinableFor(Employee $employee): ?self
    {
        $now = now();

        return static::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('status', '!=', 'CANCELLED')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->whereHas('participants', fn ($q) => $q->where('employee_id', $employee->id))
            ->orderBy('start_at')
            ->first();
    }
}
