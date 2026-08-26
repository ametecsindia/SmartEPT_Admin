<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeDevice extends Model
{
    /**
     * Revoke THIS device's agent token, so the running agent gets a 401 on its next
     * heartbeat and returns itself to the login screen (main.js handleSessionRevoked()).
     *
     * 26-Aug-2026 — every caller used to walk `employee?->user?->tokens()`. The token is
     * created on `$request->user()` at register time, which is not guaranteed to be the same
     * account as `employees.user_id`, and is null outright when the employee has no linked
     * user. Both cases revoked NOTHING and said nothing: the server closed the session and
     * marked the device FORCE_LOGOUT while the agent, still holding a live token, carried on
     * tracking and showing "Signed in". The token's NAME already carries the unique
     * device_uuid, so match on that and the account holding it stops mattering.
     *
     * Returns how many tokens were deleted — 0 is a real signal (nothing was revoked).
     */
    public function revokeAgentToken(): int
    {
        return \Laravel\Sanctum\PersonalAccessToken::where('name', 'device:' . $this->device_uuid)->delete();
    }

    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['device_token_hash'];

    protected $casts = [
        'camera_available'     => 'boolean',
        'microphone_available' => 'boolean',
        'last_heartbeat_at'    => 'datetime',
        'last_sync_at'         => 'datetime',
        'registered_at'        => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
