<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'date_of_joining'   => 'date',
        'date_of_relieving' => 'date',
        // A dated enforcement exemption. Cast so a comparison is a date
        // comparison and not a string one — '2026-9-1' and '2026-09-01' are the
        // same day and sort differently as text.
        'enforcement_exempt_from'  => 'date',
        'enforcement_exempt_until' => 'date',
    ];

    public function branch()      { return $this->belongsTo(Branch::class); }
    public function department()   { return $this->belongsTo(Department::class); }
    public function team()         { return $this->belongsTo(Team::class); }
    public function designation()  { return $this->belongsTo(Designation::class); }
    public function shift()        { return $this->belongsTo(Shift::class); }
    public function manager()      { return $this->belongsTo(User::class, 'manager_user_id'); }
    public function reportingManager() { return $this->belongsTo(User::class, 'reporting_manager_user_id'); }
    public function user()         { return $this->belongsTo(User::class); }
    public function devices()      { return $this->hasMany(EmployeeDevice::class); }
    public function consents()     { return $this->hasMany(EmployeeMonitoringConsent::class); }

    /** Who granted this person's enforcement exemption. Null when there is none. */
    public function enforcementExemptBy() { return $this->belongsTo(User::class, 'enforcement_exempt_by_user_id'); }

    /**
     * Is this person signed in to the AGENT right now?
     *
     * Not "is this their PC" and not "are they logged into Windows". Enforcement
     * follows the SmartEPT sign-in, because that is the only event that says
     * WHICH EMPLOYEE is at the keyboard — a shared PC has one Windows account and
     * several people through the day.
     */
    public function isSignedIn(): bool
    {
        return EmployeeLoginSession::withoutGlobalScopes()
            ->where('employee_id', $this->id)
            ->whereNull('logout_at')
            ->exists();
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . (string) $this->last_name);
    }
}
