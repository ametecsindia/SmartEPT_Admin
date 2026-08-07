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

        // Enforce the company's storage quota BEFORE writing — auto-trim the oldest
        // evidence to make room (a per-client hard cap the admin can raise = "buy more").
        $this->enforceQuota($companyId, (int) $file->getSize());

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

    /**
     * Hard per-client storage quota (companies.storage_quota_mb; null/0 = unlimited).
     * When a new upload would push the company over its cap, delete its OLDEST
     * evidence until the incoming file fits. Runs only when a quota is set, so
     * unlimited clients pay no query cost.
     *
     * ponytail: trims strictly oldest-first across all evidence types; violation
     * screenshots are not specially spared here — add a filter if that's required.
     */
    private function enforceQuota(int $companyId, int $incomingBytes): void
    {
        $quotaMb = \App\Models\Company::whereKey($companyId)->value('storage_quota_mb');
        if (! $quotaMb || $quotaMb <= 0) {
            return; // unlimited
        }
        $quotaBytes = (int) $quotaMb * 1048576; // MB -> bytes
        $used = (int) StorageFile::where('company_id', $companyId)->sum('size_bytes');
        $need = ($used + $incomingBytes) - $quotaBytes;
        if ($need <= 0) {
            return; // fits within the cap
        }

        $freed = 0;
        // Oldest first. id order == insertion order, and is index-friendly for delete-as-we-go.
        foreach (StorageFile::where('company_id', $companyId)->orderBy('id')->lazyById(100) as $old) {
            try {
                Storage::disk($old->storage_driver)->delete($old->storage_key);
            } catch (\Throwable $e) {
                // object already gone / disk hiccup — still drop the row so space is reclaimed
            }
            $freed += (int) $old->size_bytes;
            $old->delete();
            if ($freed >= $need) {
                break;
            }
        }
    }
}
