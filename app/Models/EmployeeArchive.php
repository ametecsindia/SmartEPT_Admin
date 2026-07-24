<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A complete backup of a deleted employee (Ejaz 24-Jul). See the migration for the
 * layout. `snapshot`/`counts` are the compact DB copy; the ZIP at storage_driver/
 * storage_key holds the full row-level export plus the actual screenshot/webcam files.
 */
class EmployeeArchive extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot'    => 'array',
        'counts'      => 'array',
        'archived_at' => 'datetime',
        'file_size'   => 'integer',
        'media_files' => 'integer',
    ];

    public function archivedBy() { return $this->belongsTo(User::class, 'archived_by_user_id'); }
}
