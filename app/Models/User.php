<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'last_login_at'        => 'datetime',
            'password'             => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function branch()  { return $this->belongsTo(Branch::class); }
    public function role()    { return $this->belongsTo(Role::class); }

    /** Linked employee record, if this login belongs to a tracked employee. */
    public function employee() { return $this->hasOne(Employee::class); }

    public function roleSlug(): ?string
    {
        return $this->role?->slug;
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleSlug() === 'SUPER_ADMIN';
    }

    public function hasRole(string ...$slugs): bool
    {
        return in_array($this->roleSlug(), $slugs, true);
    }

    /** Super Admin implicitly has every permission. */
    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        $role = $this->role;
        if (! $role) {
            return false;
        }
        return $role->permissions()->where('slug', $slug)->exists();
    }

    public function permissionSlugs(): array
    {
        if ($this->isSuperAdmin()) {
            return Permission::query()->pluck('slug')->all();
        }
        return $this->role
            ? $this->role->permissions()->pluck('slug')->all()
            : [];
    }
}
