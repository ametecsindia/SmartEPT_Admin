<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** PC Audit Log (04-Oct-2026): one "Run audit for all PCs" job and its CSV file. */
class PcAuditReport extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'date_from' => 'date', 'date_to' => 'date',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
