<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeScreenshotLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['captured_at' => 'datetime'];

    public function employee()    { return $this->belongsTo(Employee::class); }
    public function storageFile()  { return $this->belongsTo(StorageFile::class); }
}
