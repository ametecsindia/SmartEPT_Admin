<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use Illuminate\Support\Carbon;

/**
 * One biometric attendance API. Adding a third vendor means adding ONE class that
 * implements this and listing it in ProviderRegistry — nothing else in the punch
 * pipeline (dedupe, mapping, attendance merge, gate, derivation) changes.
 *
 * A provider's only job is: talk to the vendor, hand back normalized punch rows.
 * It never decides IN vs OUT policy beyond what the vendor itself reports — the
 * device's punch_direction_mode does that, in PunchDirectionResolver.
 */
interface PunchProvider
{
    /** Stable key stored in biometric_devices.provider_key. */
    public function key(): string;

    /** Label shown in the console's provider dropdown. */
    public function label(): string;

    /**
     * Fetch punches in [$from, $to].
     *
     * @return array<int, array{code:string, name:?string, punched_at:Carbon, mc:?string, direction:?string}>
     *         direction is 'IN', 'OUT', or null when the vendor did not say.
     */
    public function fetch(BiometricDevice $device, Carbon $from, Carbon $to): array;

    /**
     * First ~1500 chars of the last response body. The console shows this when nothing
     * parsed, so a field-mapping gap is visible in one click instead of one support call.
     */
    public function lastRaw(): ?string;
}
