<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 07-Oct-2026: a Popup Alert for one console user (Audit & Ops → Notifications). */
class AlertPopup extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['seen_at' => 'datetime'];
}
