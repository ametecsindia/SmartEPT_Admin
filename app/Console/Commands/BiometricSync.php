<?php

namespace App\Console\Commands;

use App\Models\BiometricDevice;
use App\Services\BiometricCloudSync;
use Illuminate\Console\Command;

/**
 * Hourly cloud biometric import (Ejaz 17-Jul): every ACTIVE device with
 * "Enable automatic hourly sync" ticked pulls its punches from the cloud
 * attendance API into BiometricLog + Attendance. Best-effort per device —
 * one provider being down never blocks the others.
 */
class BiometricSync extends Command
{
    protected $signature = 'smartept:biometric-sync {--device= : Sync one device id (even if hourly sync is off)} {--days=2 : How many days back to pull}';

    protected $description = 'Pull punches from cloud biometric providers (eTimeOffice etc.) into attendance';

    public function handle(BiometricCloudSync $sync): int
    {
        $q = BiometricDevice::withoutGlobalScopes()->where('status', 'ACTIVE');
        $this->option('device')
            ? $q->where('id', (int) $this->option('device'))
            : $q->where('sync_enabled', true);

        $devices = $q->get();
        if ($devices->isEmpty()) {
            $this->info('No cloud biometric devices with hourly sync enabled.');

            return self::SUCCESS;
        }

        foreach ($devices as $d) {
            try {
                $r = $sync->sync($d, max(1, (int) $this->option('days')));
                $this->line(sprintf('device %d (%s): %s', $d->id, $d->provider ?: $d->name, $r['message']));
            } catch (\Throwable $e) {
                $this->error(sprintf('device %d (%s): %s', $d->id, $d->provider ?: $d->name, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
