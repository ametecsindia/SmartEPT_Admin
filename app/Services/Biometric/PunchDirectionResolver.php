<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use Illuminate\Support\Carbon;

/**
 * How a reader decides IN vs OUT (Ejaz, 28-Aug-2026).
 *
 *   AUTO      the rule SmartEPT has always used: the configured IN/OUT machine numbers
 *             override the feed's own flag, the feed flag is used next, and anything
 *             still undecided alternates within the fetched batch. Every device that
 *             existed before 28-Aug is AUTO, so nothing about eTimeOffice changes.
 *   IN_ONLY   entry reader — every punch is IN.
 *   OUT_ONLY  exit reader — every punch is OUT.
 *   IN_OUT    ONE reader used for both directions. The direction comes from the
 *             employee's punch sequence for that day: 1st IN, 2nd OUT, 3rd IN, 4th OUT…
 *
 * The IN_OUT sequence is recomputed from the whole day in the database, not from the
 * batch that happened to arrive — so a re-sync, a late-arriving punch, or a punch that
 * lands out of order still produces the same, correct alternation. Punches from
 * IN-only / OUT-only readers are not renumbered; they simply set the parity for the
 * alternating punches that follow them, which is what makes a mixed site work:
 *
 *   09:05  turnstile (IN only)   → IN
 *   13:10  3rd-floor reader (IN+OUT) → OUT   (previous punch was IN)
 *   14:00  3rd-floor reader (IN+OUT) → IN
 *   18:15  turnstile (OUT only)  → OUT
 */
class PunchDirectionResolver
{
    public const MODES = ['AUTO', 'IN_ONLY', 'OUT_ONLY', 'IN_OUT'];

    public const DEFAULT_MODE = 'AUTO';

    /** @var array<int, string> device id → mode, memoized per request. */
    private array $modeCache = [];

    public static function mode(?BiometricDevice $device): string
    {
        $m = strtoupper(trim((string) ($device?->punch_direction_mode ?? '')));

        return in_array($m, self::MODES, true) ? $m : self::DEFAULT_MODE;
    }

    /**
     * Apply a reader's fixed direction to freshly-fetched rows, before they are stored.
     * AUTO returns the rows untouched — that is the whole backward-compatibility promise.
     *
     * @param  array<int, array{direction:?string}>  $rows
     */
    public function applyDeviceMode(BiometricDevice $device, array $rows): array
    {
        $mode = self::mode($device);
        if ($mode === self::DEFAULT_MODE) {
            return $rows;
        }

        foreach ($rows as &$r) {
            $r['direction'] = match ($mode) {
                'IN_ONLY'  => 'IN',
                'OUT_ONLY' => 'OUT',
                // IN_OUT is settled against the stored day, once the punches are in.
                default    => null,
            };
        }
        unset($r);

        return $rows;
    }

    /**
     * Re-derive IN/OUT for every IN_OUT punch this employee has on this day.
     *
     * @return array<int, array{id:int, employee_id:int, punch_type:string, punched_at:Carbon}>
     *         only the punches whose direction actually changed.
     */
    public function resequenceDay(int $companyId, int $employeeId, string $date): array
    {
        $logs = BiometricLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereDate('punched_at', $date)
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get(['id', 'biometric_device_id', 'punch_type', 'punched_at']);

        if ($logs->isEmpty()) {
            return [];
        }

        $day = $logs->map(fn ($log) => [
            'mode'       => $this->modeOf($log->biometric_device_id),
            'punch_type' => (string) $log->punch_type,
        ])->all();

        $changed = [];
        foreach (self::sequence($day) as $i => $want) {
            $log = $logs[$i];
            BiometricLog::withoutGlobalScopes()->whereKey($log->id)->update(['punch_type' => $want]);
            $changed[] = [
                'id'          => (int) $log->id,
                'employee_id' => $employeeId,
                'punch_type'  => $want,
                'punched_at'  => $log->punched_at instanceof Carbon ? $log->punched_at : Carbon::parse($log->punched_at),
            ];
        }

        return $changed;
    }

    /**
     * The alternating rule itself, with no database in it.
     *
     * @param  array<int, array{mode:string, punch_type:string}>  $day  one day's punches, in time order
     * @return array<int, string>  index → the direction it should have, ONLY for the ones that must change
     */
    public static function sequence(array $day): array
    {
        $changes = [];
        $previous = null; // last known IN/OUT of the day, whichever reader produced it

        foreach ($day as $i => $punch) {
            if (($punch['mode'] ?? self::DEFAULT_MODE) !== 'IN_OUT') {
                // A fixed reader (or a legacy AUTO one) keeps its direction and sets parity.
                if (in_array($punch['punch_type'], ['IN', 'OUT'], true)) {
                    $previous = $punch['punch_type'];
                }

                continue;
            }

            // A break punch is not a door direction — it neither alternates nor sets parity.
            if (in_array($punch['punch_type'], ['BREAK_IN', 'BREAK_OUT'], true)) {
                continue;
            }

            $want = $previous === 'IN' ? 'OUT' : 'IN'; // first punch of the day is IN
            if ($punch['punch_type'] !== $want) {
                $changes[$i] = $want;
            }
            $previous = $want;
        }

        return $changes;
    }

    /**
     * Resequence every (employee, day) touched by a batch of punch rows that came from
     * an IN_OUT reader.
     *
     * @param  array<int, array{employee_id:?int, punched_at:mixed}>  $rows
     * @return array<int, array{id:int, employee_id:int, punch_type:string, punched_at:Carbon}>
     */
    public function resequenceForRows(int $companyId, array $rows): array
    {
        $pairs = [];
        foreach ($rows as $r) {
            $employeeId = $r['employee_id'] ?? null;
            if (! $employeeId) {
                continue; // unmapped punches settle after map-employee back-fills them
            }
            $at = $r['punched_at'] instanceof Carbon ? $r['punched_at'] : Carbon::parse($r['punched_at']);
            $pairs[$employeeId . '|' . $at->toDateString()] = [(int) $employeeId, $at->toDateString()];
        }

        $changed = [];
        foreach ($pairs as [$employeeId, $date]) {
            foreach ($this->resequenceDay($companyId, $employeeId, $date) as $c) {
                $changed[] = $c;
            }
        }

        return $changed;
    }

    /** Does any device in this company use alternating IN/OUT? (cheap guard for the ingest path) */
    public function anyDirectionalDevice(int $companyId): bool
    {
        return BiometricDevice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('punch_direction_mode')
            ->where('punch_direction_mode', '!=', self::DEFAULT_MODE)
            ->exists();
    }

    private function modeOf(?int $deviceId): string
    {
        if (! $deviceId) {
            return self::DEFAULT_MODE;
        }
        if (! array_key_exists($deviceId, $this->modeCache)) {
            $device = BiometricDevice::withoutGlobalScopes()->find($deviceId);
            $this->modeCache[$deviceId] = self::mode($device);
        }

        return $this->modeCache[$deviceId];
    }
}
