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
        // Cloud Storage (GCS) when the admin has connected a bucket AND the disk is
        // actually registered (AppServiceProvider registers it only when the SDK is
        // installed + the key decrypts). Otherwise the local disk / configured default,
        // so a half-set config never loses a screenshot.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('settings')
                && \App\Models\Setting::get('gcs_enabled') === '1'
                && array_key_exists('gcs', config('filesystems.disks', []))) {
                return 'gcs';
            }
        } catch (\Throwable $e) {
            // fall through to the safe default
        }

        return 'evidence';
    }

    public function storeUpload(UploadedFile $file, int $companyId, int $employeeId, string $type, ?int $retentionDays = null): StorageFile
    {
        $ext = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
        $dir = sprintf('smartept/%d/%s/%s', $companyId, strtolower($type), now()->format('Y-m-d'));
        $name = Str::uuid()->toString() . '.' . $ext;

        $disk = $this->disk();
        $path = $file->storeAs($dir, $name, ['disk' => $disk]);

        // storeAs() returns false when the write fails (e.g. a GCS bucket permission
        // or uniform-access ACL rejection). Fail loudly so the agent retries and we
        // NEVER persist a broken row with storage_key "0" pointing at nothing.
        if ($path === false) {
            throw new \RuntimeException("Failed to store {$type} to disk [{$disk}] — check the storage/bucket configuration.");
        }

        return StorageFile::create([
            'company_id'     => $companyId,
            'employee_id'    => $employeeId,
            'file_type'      => $type,
            'storage_driver' => $disk,
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
