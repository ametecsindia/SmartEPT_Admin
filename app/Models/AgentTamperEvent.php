<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * QA Phase 2 (A8) — an audit row for every agent exit / uninstall / gate-override /
 * unexpected service-stop attempt. Written by the agent (POST /agent/tamper-attempt)
 * and by the server-side gate override.
 */
class AgentTamperEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'metadata'    => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }

    public function approver() { return $this->belongsTo(User::class, 'approver_user_id'); }
}
