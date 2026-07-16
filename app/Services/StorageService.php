<?php

namespace App\Services;

use App\Models\StorageFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Central place that puts screenshot/webcam binaries into the configured object store
 * and records a storage_files row. Binaries never touch the database. On Laragon the
 * default 'local' disk (storage/app) is used; production swaps in MinIO/S3/Azure/GCP.
 */
class StorageService
{
    public function disk(): string
    {
        // 'local' works out of the box on Laravel/Laragon. Production sets this per company.
        return config('smartept.storage_disk', 'local');
    }

    public function storeUpload(UploadedFile $file, int $companyId, int $employeeId, string $type, ?int $retentionDays = null): StorageFile
    {
        $ext = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
        $dir = sprintf('smartept/%d/%s/%s', $companyId, strtolower($type), now()->format('Y-m-d'));
        $name = Str::uuid()->toString() . '.' . $ext;

        $path = $file->storeAs($dir, $name, ['disk' => $this->disk()]);

        return StorageFile::create([
            'company_id'     => $companyId,
            'employee_id'    => $employeeId,
            'file_type'      => $type,
            'storage_driver' => $this->disk(),
            'bucket'         => null,
            'storage_key'    => $path,
            'mime_type'      => $file->getClientMimeType(),
            'size_bytes'     => $file->getSize(),
            'checksum'       => md5_file($file->getRealPath()),
            'encrypted'      => false,
            'expires_at'     => $retentionDays ? now()->addDays($retentionDays) : null,
        ]);
    }
}
