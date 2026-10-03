<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** 30-Sep-2026: an automatic Productivity report email (Reports → Schedule Report). */
class ReportSchedule extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean', 'all_employees_own' => 'boolean', 'skip_empty' => 'boolean',
        'skip_holidays' => 'boolean', 'attach_excel' => 'boolean',
        'whatsapp_enabled' => 'boolean', 'whatsapp_admins' => 'boolean', 'whatsapp_numbers' => 'array',
        'days' => 'array', 'scope' => 'array', 'recipients' => 'array', 'extra_emails' => 'array',
        'day_of_month' => 'integer', 'last_run_at' => 'datetime',
    ];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
