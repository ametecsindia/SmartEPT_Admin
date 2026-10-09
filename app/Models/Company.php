<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    /**
     * The app clock is taken from this row at boot (AppServiceProvider::applyOrganisationTimezone).
     * Drop the cached value whenever the Organisation record is saved or removed, so changing the
     * timezone in the Organisation tab takes effect on the next request instead of up to an hour
     * later. Booted here rather than in the controller so every write path is covered.
     */
    protected static function booted(): void
    {
        $forget = fn () => \Illuminate\Support\Facades\Cache::forget('smartept:org_timezone');
        static::saved($forget);
        static::deleted($forget);
    }


    protected $guarded = ['id'];

    protected $casts = [
        'storage_settings'        => 'array',
        'mail_settings'           => 'array',
        'data_retention_days'     => 'integer',
        'agent_exit_lock_enabled' => 'boolean',
        'agent_exit_password'     => 'encrypted',
        'exclude_ip_sites'        => 'boolean',
    ];

    public function branches()    { return $this->hasMany(Branch::class); }
    public function departments()  { return $this->hasMany(Department::class); }
    public function teams()        { return $this->hasMany(Team::class); }
    public function designations() { return $this->hasMany(Designation::class); }
    public function shifts()       { return $this->hasMany(Shift::class); }
    public function employees()    { return $this->hasMany(Employee::class); }
    public function users()        { return $this->hasMany(User::class); }

    /**
     * 09-Oct-2026 (Ejaz: one place per setting) — how long a screenshot / webcam photo FILE is
     * kept. Audit & Ops → Retention is the only place; the Screenshot and Webcam policies'
     * own retention fields are gone (they expired files on a different day than the purge
     * deleted their rows). Same windows as smartept:purge; null = keep (auto-cleanup off).
     */
    public function mediaRetentionDays(bool $violationEvidence): ?int
    {
        if (! ($this->auto_cleanup_enabled ?? true)) {
            return null;
        }
        $base = (int) ($this->data_retention_days ?: config('smartept.default_retention_days', 90));

        return $violationEvidence && ($this->retention_keep_violation_evidence ?? true)
            ? (int) ($this->retention_violation_days ?: $base)
            : (int) ($this->retention_screenshots_days ?: $base);
    }
}
