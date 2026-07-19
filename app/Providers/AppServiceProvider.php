<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGcsDisk();
    }

    /**
     * Register a Google Cloud Storage disk from the admin-saved settings, so
     * screenshots/evidence can be written straight to a GCS bucket configured in the
     * console (no gcsfuse). Every step is guarded so a missing package, un-migrated
     * DB, or bad key can NEVER stop the app from booting — it just falls back to local.
     */
    private function registerGcsDisk(): void
    {
        try {
            if (! class_exists(\Google\Cloud\Storage\StorageClient::class)) {
                return; // composer packages not installed yet
            }
            if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
                return; // migration not run yet
            }
            if (\App\Models\Setting::get('gcs_enabled') !== '1') {
                return;
            }

            $bucket = \App\Models\Setting::get('gcs_bucket');
            $keyEnc = \App\Models\Setting::get('gcs_key_json');
            if (! $bucket || ! $keyEnc) {
                return;
            }
            $creds = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($keyEnc), true);
            if (! is_array($creds)) {
                return;
            }

            \Illuminate\Support\Facades\Storage::extend('gcs', function ($app, $config) {
                $client = new \Google\Cloud\Storage\StorageClient(['keyFile' => $config['key_file']]);
                $adapter = new \League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter(
                    $client->bucket($config['bucket']),
                    $config['prefix'] ?? ''
                );

                return new \Illuminate\Filesystem\FilesystemAdapter(
                    new \League\Flysystem\Filesystem($adapter),
                    $adapter,
                    $config
                );
            });

            config(['filesystems.disks.gcs' => [
                'driver'   => 'gcs',
                'bucket'   => $bucket,
                'key_file' => $creds,
                'prefix'   => '',
            ]]);
        } catch (\Throwable $e) {
            // Never let storage config break the application boot.
        }
    }
}
