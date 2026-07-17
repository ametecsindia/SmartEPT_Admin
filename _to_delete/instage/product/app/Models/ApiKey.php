<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['scopes' => 'array', 'active' => 'boolean', 'last_used_at' => 'datetime'];
    protected $hidden = ['key_hash'];

    public function company() { return $this->belongsTo(Company::class); }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?: [];
        return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
    }
}
