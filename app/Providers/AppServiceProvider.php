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
        $this->applyOrganisationTimezone();
        $this->registerGcsDisk();
        $this->registerEvidenceDisk();
    }

    /**
     * Run the whole application on the timezone set in Organisation → Company (Ejaz,
     * 26-Aug-2026: "whether it is cloud or on premises — it should consider the timezone set
     * in the Organization tab").
     *
     * Why this rather than fixing call sites: the agent writes LOCAL wall-clock times, and the
     * server has ~125 `now()` calls compared against them — shift ends, heartbeat staleness,
     * meeting auto-close, licence checks. If PHP's clock is UTC and the tenant is IST, every
     * one of those is 5h30m out, and the failure is silent: things simply happen hours late.
     * Setting the clock once at boot makes all of them correct with no call-site churn, and
     * stops a fresh install starting wrong because .env was left at the default. The
     * Organisation tab becomes the single place a timezone is set.
     *
     * Only applied when the installation holds exactly ONE company (every on-prem install and
     * the current cloud console). With several tenants there is no single right answer for a
     * process-wide clock, so APP_TIMEZONE stands and the per-company resolvers — bizTz() for
     * requests, ResolvesLocalNow for console commands — remain authoritative.
     *
     * Guarded end to end: an un-migrated database, a missing table or an unreadable cache must
     * never stop the app booting (this runs during `migrate` and `key:generate` too).
     */
    private function applyOrganisationTimezone(): void
    {
        try {
            $tz = \Illuminate\Support\Facades\Cache::remember('smartept:org_timezone', 3600, function () {
                $rows = \App\Models\Company::withoutGlobalScopes()->limit(2)->pluck('timezone');

                return $rows->count() === 1 ? (string) $rows->first() : '';
            });

            if ($tz && $tz !== config('app.timezone') && in_array($tz, timezone_identifiers_list(), true)) {
                config(['app.timezone' => $tz]);
                date_default_timezone_set($tz);
            }
        } catch (\Throwable $e) {
            // No DB yet, no cache store, or a bad value — keep booting on APP_TIMEZONE.
        }
    }

    /**
     * Register a Google Cloud Storage disk from the admin-saved settings, so
     * screenshots/evidence can be written straight to a GCS bucket configured in the
     * console (no gcsfuse). Every step is guarded so a missing package, un-migrated
     * DB, or bad key can NEVER stop the app from booting — it just falls back to local.
     */
    /**
     * The 'evidence' disk holds screenshots/webcam when NOT using cloud. Root
     * defaults to storage/app; the admin can point it at a folder on the same
     * server or a LAN/NAS share (Settings -> storage_local_path). Guarded so a
     * bad path never breaks boot.
     */
    private function registerEvidenceDisk(): void
    {
        $root = storage_path('app');
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                $p = \App\Models\Setting::get('storage_local_path');
                if ($p && trim($p) !== '') {
                    $root = rtrim(trim($p), '/\\');
                }
            }
        } catch (\Throwable $e) {
            // keep the default root
        }
        config(['filesystems.disks.evidence' => [
            'driver' => 'local',
            'root'   => $root,
            'throw'  => false,
        ]]);
    }

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
                // Uniform bucket-level access (Google's default) forbids per-object ACLs.
                // Use the ubla visibility handler so uploads never attempt an ACL call —
                // without this, every write silently fails and storage_key is saved as "0".
                // This handler is also correct for fine-grained buckets, so it's used always.
                $adapter = new \League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter(
                    $client->bucket($config['bucket']),
                    $config['prefix'] ?? '',
                    new \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility()
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
