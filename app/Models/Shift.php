<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Shift extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = [
        'working_days'            => 'array',
        'crosses_midnight'        => 'boolean',
        'restrict_login_to_shift' => 'boolean',
    ];

    public function employees() { return $this->hasMany(Employee::class); }

    /**
     * May an agent sign-in be accepted at this wall-clock instant? (Ejaz, 26-Aug-2026 —
     * the AGENT app only; the admin console never reaches this code.)
     *
     * The window is [start_time, end_time + post_shift_auto_logout_minutes]. Reusing the auto
     * sign-out grace as the tail is deliberate: letting someone sign in at an instant the
     * server would sign them straight back out is not a window, so the two settings describe
     * one boundary instead of two that can disagree.
     *
     * Yesterday is tested as well as today, so a night shift is judged on the window it is
     * actually inside — at 02:00 a 22:00–06:00 employee belongs to YESTERDAY's window. For a
     * day shift the extra candidate simply never matches.
     *
     * A shift with no times has no window to enforce and therefore allows: nobody may be
     * locked out of their own PC because a shift record is half-filled.
     *
     * @param  Carbon  $when  local wall clock, the same frame the agent stores its times in
     */
    public function coversSignInAt(Carbon $when): bool
    {
        if (! $this->start_time || ! $this->end_time) {
            return true;
        }

        foreach ([0, -1] as $dayOffset) {
            $day = $when->copy()->startOfDay()->addDays($dayOffset)->toDateString();
            $start = Carbon::parse($day . ' ' . $this->start_time);
            $end = Carbon::parse($day . ' ' . $this->end_time);

            if ($this->crosses_midnight || $end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }
            $end->addMinutes((int) ($this->post_shift_auto_logout_minutes ?? 0));

            if ($when->greaterThanOrEqualTo($start) && $when->lessThanOrEqualTo($end)) {
                return true;
            }
        }

        return false;
    }

    /** "09:00–18:00" — for the refusal the employee reads on the agent's sign-in screen. */
    public function windowLabel(): string
    {
        return Carbon::parse($this->start_time)->format('H:i') . '–' . Carbon::parse($this->end_time)->format('H:i');
    }
}
