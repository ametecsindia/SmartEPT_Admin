<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'meeting_date'  => 'date',
        'start_at'      => 'datetime',
        'end_at'        => 'datetime',
        'actual_end_at' => 'datetime',
    ];

    public function participants() { return $this->hasMany(MeetingParticipant::class); }
    public function sessions() { return $this->hasMany(EmployeeMeetingSession::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function endedBy() { return $this->belongsTo(User::class, 'ended_by_user_id'); }

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
    /** Statuses in which a meeting is over and can never be joined again. */
    public const TERMINAL_STATUSES = ['CANCELLED', 'COMPLETED', 'AUTO_CLOSED', 'NO_SHOW'];

    public static function reminderDueFor(Employee $employee): ?self
    {
        $now = now();

        // "start_at minus reminder_minutes has passed" — DATE_SUB is MySQL-only
        // syntax; it 500'd every agent register/heartbeat under sqlite (tests).
        $reminderDue = \Illuminate\Support\Facades\DB::getDriverName() === 'sqlite'
            ? "datetime(start_at, '-' || reminder_minutes || ' minutes') <= ?"
            : 'DATE_SUB(start_at, INTERVAL reminder_minutes MINUTE) <= ?';

        return static::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->whereNull('actual_end_at')
            ->whereNotNull('reminder_minutes')
            ->where('start_at', '>', $now)
            ->whereRaw($reminderDue, [$now->toDateTimeString()])
            ->whereHas('participants', fn ($q) => $q->where('employee_id', $employee->id))
            ->orderBy('start_at')
            ->first();
    }

    public static function currentJoinableFor(Employee $employee): ?self
    {
        $now = now();

        // A meeting is joinable ONLY while it is genuinely live: not in any terminal
        // status, never actually ended, and NOW inside the scheduled window. Once it is
        // Completed / Cancelled / Auto-closed (or actual_end_at is stamped), it drops out
        // here, so the agent's Join button clears on the very next heartbeat.
        return static::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->whereNull('actual_end_at')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->whereHas('participants', fn ($q) => $q->where('employee_id', $employee->id))
            ->orderBy('start_at')
            ->first();
    }
}
