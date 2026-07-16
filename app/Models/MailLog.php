<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per outbound-mail attempt (sent | failed | skipped).
 * Written by App\Services\MailService — never insert directly.
 */
class MailLog extends Model
{
    protected $guarded = ['id'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
