<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Cloud Storage (GCS) settings — lets a non-technical admin connect a Google Cloud
 * Storage bucket from the console (no gcsfuse / SSH): paste bucket + service-account
 * JSON key, Test, Save. The service-account key is stored encrypted. When enabled and
 * the SDK is installed, screenshots/evidence are written straight to the bucket.
 */
class StorageConfigController extends Controller
{
    private function sdkInstalled(): bool
    {
        return class_exists(\Google\Cloud\Storage\StorageClient::class);
    }

    /** GET /api/ops/storage-config — current settings (the key itself is never returned). */
    public function show(): JsonResponse
    {
        return response()->json([
            'enabled'       => Setting::get('gcs_enabled') === '1',
            'bucket'        => Setting::get('gcs_bucket'),
            'project_id'    => Setting::get('gcs_project_id'),
            'has_key'       => (bool) Setting::get('gcs_key_json'),
            'sdk_installed' => $this->sdkInstalled(),
            'active_disk'   => app(StorageService::class)->disk(),
            'local_path'    => Setting::get('storage_local_path') ?: '',
        ]);
    }

    /** PUT /api/ops/storage-config — save. Key JSON is replaced only when a new one is sent. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'    => ['required', 'boolean'],
            'bucket'     => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'string', 'max:255'],
            'key_json'   => ['nullable', 'string'],
        ]);

        if (! empty($data['key_json'])) {
            $decoded = json_decode($data['key_json'], true);
            if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'service_account') {
                return response()->json(['message' => 'That does not look like a Google service-account JSON key (expected "type": "service_account").'], 422);
            }
            Setting::put('gcs_key_json', Crypt::encryptString($data['key_json']));
        }

        Setting::put('gcs_bucket', $data['bucket'] ?? '');
        Setting::put('gcs_project_id', $data['project_id'] ?? '');

        if ($data['enabled']) {
            if (! $this->sdkInstalled()) {
                return response()->json(['message' => 'Cloud storage libraries are not installed on this server yet. Ask IT to run, once, in the app folder: composer require google/cloud-storage league/flysystem-google-cloud-storage — then turn this on.'], 422);
            }
            if (! Setting::get('gcs_bucket') || ! Setting::get('gcs_key_json')) {
                return response()->json(['message' => 'Enter the bucket name and paste the service-account key before turning this on.'], 422);
            }
        }

        Setting::put('gcs_enabled', $data['enabled'] ? '1' : '0');

        return response()->json(['ok' => true, 'active_disk' => app(StorageService::class)->disk()]);
    }

    /** PUT /api/ops/storage-local — set the on-premise / LAN / NAS storage folder. */
    public function saveLocal(Request $request): JsonResponse
    {
        $data = $request->validate(['local_path' => ['nullable', 'string', 'max:500']]);
        $path = trim($data['local_path'] ?? '');
        if ($path !== '' && ($err = $this->checkWritable($path))) {
            return response()->json(['message' => $err], 422);
        }
        Setting::put('storage_local_path', $path);

        return response()->json(['ok' => true]);
    }

    /** POST /api/ops/storage-local/test — verify the folder exists and is writable. */
    public function testLocal(Request $request): JsonResponse
    {
        $path = trim((string) $request->input('local_path'));
        if ($path === '') {
            return response()->json(['ok' => false, 'message' => 'Enter a folder path first (or leave blank to use the default app storage).']);
        }
        $err = $this->checkWritable($path);

        return $err
            ? response()->json(['ok' => false, 'message' => $err])
            : response()->json(['ok' => true, 'message' => 'Folder is reachable and writable — new evidence will be stored here when cloud is off.']);
    }

    /** Create the folder if needed and confirm the server can write to it. */
    private function checkWritable(string $path): ?string
    {
        $path = rtrim($path, '/\\');
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            return 'That folder does not exist and could not be created. Check the path (and that a network share is reachable).';
        }
        $probe = $path . DIRECTORY_SEPARATOR . '.smartept_write_test';
        if (@file_put_contents($probe, 'ok') === false) {
            return 'The folder exists but the server cannot write to it. Grant the Windows service account write access (or share write permission for a network path).';
        }
        @unlink($probe);

        return null;
    }

    /** POST /api/ops/storage-config/test — verify the bucket is reachable. */
    public function test(Request $request): JsonResponse
    {
        if (! $this->sdkInstalled()) {
            return response()->json(['ok' => false, 'message' => 'Cloud storage libraries not installed. In the app folder run once: composer require google/cloud-storage league/flysystem-google-cloud-storage']);
        }

        $bucket = $request->input('bucket') ?: Setting::get('gcs_bucket');
        $keyJson = $request->input('key_json');
        if (! $keyJson && Setting::get('gcs_key_json')) {
            $keyJson = Crypt::decryptString(Setting::get('gcs_key_json'));
        }
        if (! $bucket || ! $keyJson) {
            return response()->json(['ok' => false, 'message' => 'Enter the bucket name and the service-account key first.']);
        }

        try {
            $creds = json_decode($keyJson, true);
            $client = new \Google\Cloud\Storage\StorageClient(['keyFile' => $creds]);
            $exists = $client->bucket($bucket)->exists();

            return response()->json([
                'ok'      => $exists,
                'message' => $exists
                    ? 'Connected — bucket "' . $bucket . '" is reachable. You can turn on cloud storage.'
                    : 'Reached Google, but bucket "' . $bucket . '" was not found. Check the bucket name and that the key has access to it.',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Could not connect: ' . $e->getMessage()]);
        }
    }
}
