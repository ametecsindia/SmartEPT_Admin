<?php

namespace App\Console\Commands;

use App\Models\BiometricDevice;
use App\Services\BiometricCloudSync;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Continuous cloud biometric import (Ejaz 17-Jul; modes added Section 8, 21-Jul).
 * Runs every 5 minutes from the scheduler; each ACTIVE device decides whether it is
 * DUE based on its sync mode:
 *   - INTERVAL  — every sync_interval_minutes (default 5).
 *   - SCHEDULED — at each configured local time in sync_times (matched to the 5-min tick).
 *   - MANUAL    — never here; only the console "Sync now" button.
 * An overlap lock per device stops two runs colliding, and last_sync_ok_at / next_sync_at
 * / last_sync_counts are recorded so the console shows real status without anyone clicking.
 * Best-effort per device — one provider being down never blocks the others.
 *
 * NOTE: this only runs if the OS scheduler calls `php artisan schedule:run` every minute
 * (Windows Task Scheduler / cron). If auto-sync never happens, verify that entry first.
 */
class BiometricSync extends Command
{
    protected $signature = 'smartept:biometric-sync {--device= : Sync one device id now (ignores mode/due checks)} {--days=2 : How many days back to pull}';

    protected $description = 'Pull punches from cloud biometric providers (eTimeOffice etc.) into attendance';

    public function handle(BiometricCloudSync $sync): int
    {
        $days = max(1, (int) $this->option('days'));

        if ($this->option('device')) {
            $device = BiometricDevice::withoutGlobalScopes()->find((int) $this->option('device'));
            if (! $device) {
                $this->error('Device not found.');

                return self::FAILURE;
            }
            $this->runDevice($sync, $device, $days);

            return self::SUCCESS;
        }

        $devices = BiometricDevice::withoutGlobalScopes()->where('status', 'ACTIVE')->get();
        $ran = 0;
        foreach ($devices as $d) {
            if (! $this->isAutomatic($d) || ! $this->isDue($d)) {
                continue;
            }
            $this->runDevice($sync, $d, $days);
            $ran++;
        }

        if ($ran === 0) {
            $this->info('No cloud biometric devices were due for automatic sync.');
        }

        return self::SUCCESS;
    }

    /** A device syncs automatically unless it is explicitly MANUAL (or auto is off). */
    private function isAutomatic(BiometricDevice $d): bool
    {
        return $this->mode($d) !== 'MANUAL';
    }

    private function mode(BiometricDevice $d): string
    {
        // Fall back to the legacy sync_enabled flag if sync_mode is somehow blank.
        return $d->sync_mode ?: ($d->sync_enabled ? 'INTERVAL' : 'MANUAL');
    }

    /** Is this device due for a sync on THIS 5-minute tick? */
    private function isDue(BiometricDevice $d): bool
    {
        // Never double-run inside the same few minutes (covers overlapping ticks).
        if ($d->last_sync_at && $d->last_sync_at->gt(now()->subMinutes(4))) {
            return false;
        }

        if ($this->mode($d) === 'SCHEDULED') {
            foreach ((is_array($d->sync_times) ? $d->sync_times : []) as $t) {
                if (! preg_match('/^\d{1,2}:\d{2}$/', (string) $t)) {
                    continue;
                }
                // app timezone == company timezone (Asia/Kolkata) — now() is already local.
                $target = Carbon::today()->setTimeFromTimeString($t);
                if (now()->betweenIncluded($target, (clone $target)->addMinutes(5))) {
                    return true;
                }
            }

            return false;
        }

        // INTERVAL: due when enough minutes have passed since the last sync.
        $interval = max(1, (int) ($d->sync_interval_minutes ?: 5));

        return ! $d->last_sync_at || $d->last_sync_at->lte(now()->subMinutes($interval));
    }

    private function runDevice(BiometricCloudSync $sync, BiometricDevice $d, int $days): void
    {
        // Overlap guard — if a run for this device is already in flight, skip.
        $lock = Cache::lock('biosync:device:' . $d->id, 290);
        if (! $lock->get()) {
            $this->line(sprintf('device %d (%s): skipped — a sync is already running', $d->id, $d->provider ?: $d->name));

            return;
        }

        try {
            $r = $sync->sync($d, $days);
            $d->forceFill([
                'last_sync_ok_at'  => now(),
                'next_sync_at'     => $this->nextRunAt($d),
                'last_sync_counts' => [
                    'fetched'   => $r['fetched'] ?? null,
                    'stored'    => $r['stored'] ?? null,
                    'duplicate' => $r['duplicate'] ?? null,
                    'corrected' => $r['corrected'] ?? null,
                    'unmapped'  => $r['unmapped'] ?? null,
                ],
            ])->save();
            $this->line(sprintf('device %d (%s): %s', $d->id, $d->provider ?: $d->name, $r['message']));
        } catch (\Throwable $e) {
            // sync() already stamped last_sync_at + an ERROR result; record the next try.
            $d->forceFill(['next_sync_at' => $this->nextRunAt($d)])->save();
            $this->error(sprintf('device %d (%s): %s', $d->id, $d->provider ?: $d->name, $e->getMessage()));
        } finally {
            optional($lock)->release();
        }
    }

    /** When should this device sync next (for the console status panel)? */
    private function nextRunAt(BiometricDevice $d): ?Carbon
    {
        if ($this->mode($d) === 'SCHEDULED') {
            $times = collect(is_array($d->sync_times) ? $d->sync_times : [])
                ->filter(fn ($t) => preg_match('/^\d{1,2}:\d{2}$/', (string) $t))
                ->map(fn ($t) => Carbon::today()->setTimeFromTimeString($t))
                ->sort()
                ->values();
            $upcoming = $times->first(fn ($t) => $t->isFuture());

            return $upcoming ?: ($times->isNotEmpty() ? $times->first()->addDay() : null);
        }

        return now()->addMinutes(max(1, (int) ($d->sync_interval_minutes ?: 5)));
    }
}
