<?php

namespace App\Services;

use App\Http\Controllers\Api\BiometricController;
use App\Models\BiometricDevice;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Services\Biometric\ProviderRegistry;
use App\Services\Biometric\PunchDirectionResolver;
use Illuminate\Support\Carbon;

/**
 * Cloud/on-prem biometric punch import (Ejaz 17-Jul-2026; second provider 28-Aug-2026).
 *
 * Pulls punches from a biometric attendance API and feeds them into the SAME pipeline as
 * middleware push / CSV import: BiometricLog rows + attendance merge — so imported
 * punches mark PRESENT, feed payroll and open the Gate-to-PC wall exactly like a
 * locally-pushed punch.
 *
 * Talking to the vendor is NOT this class's job any more. Each provider
 * (App\Services\Biometric\*Provider) knows its own protocol and hands back normalized
 * rows; this class does the vendor-independent half: employee resolution, dedupe,
 * direction policy, storage and the fan-out into attendance, gate and derivation.
 * Adding a third vendor touches one new class and one line of ProviderRegistry.
 *
 * IN/OUT resolution, in order:
 *   1. the reader's punch_direction_mode when it is not AUTO (IN only / OUT only /
 *      IN+OUT alternating) — see PunchDirectionResolver;
 *   2. otherwise the historical rule: configured IN/OUT machine numbers override the
 *      feed's own flag, then the feed's flag;
 *   3. anything still undecided alternates IN → OUT per employee per day.
 */
class BiometricCloudSync
{
    /** First 1500 chars of the last provider response body (for zero-parse debugging). */
    private ?string $lastRaw = null;

    public function __construct(
        private ProviderRegistry $providers,
        private PunchDirectionResolver $directions,
    ) {
    }

    /** Test connection: fetch today's punches, store NOTHING. */
    public function probe(BiometricDevice $d): array
    {
        try {
            $rows = $this->collect($d, now()->subDay(), now());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'sample' => [], 'raw' => $this->lastRaw];
        }

        $resolve = $this->employeeResolver($d);
        $mcs = collect($rows)->pluck('mc')->filter(fn ($v) => $v !== null && $v !== '')->unique()->sort()->values();

        $sample = collect($rows)->take(8)->map(function ($r) use ($resolve) {
            $hit = $resolve($r['code']);

            return [
                'code'       => $r['code'],
                'name'       => $r['name'],
                'punched_at' => $r['punched_at']->toDateTimeString(),
                'mc'         => $r['mc'],
                'direction'  => $r['direction'],
                'mapped'     => $hit['employee_id'] !== null,
            ];
        })->values()->all();

        $msg = 'Connected — ' . count($rows) . ' punch(es) parsed in the last 24h'
            . ($mcs->isNotEmpty() ? ('. Device/MC numbers seen: ' . $mcs->implode(', ')) : '');

        // Show the raw body when nothing parsed OR when no punch carried a machine
        // number — either way a field-mapping gap must be visible instantly.
        $allMcNull = count($rows) > 0 && $mcs->isEmpty();

        return [
            'ok'      => true,
            'message' => $msg,
            'sample'  => $sample,
            'raw'     => (count($rows) === 0 || $allMcNull) ? $this->lastRaw : null,
        ];
    }

    /** Pull + store punches for the last $daysBack days, fold into attendance. */
    public function sync(BiometricDevice $d, int $daysBack = 2): array
    {
        $from = now()->subDays(max(0, $daysBack - 1))->startOfDay();

        try {
            $rows = $this->collect($d, $from, now());
        } catch (\Throwable $e) {
            $d->forceFill([
                'last_sync_at'     => now(),
                'last_sync_result' => 'ERROR — ' . mb_substr($e->getMessage(), 0, 400),
            ])->save();
            throw $e;
        }

        $resolve = $this->employeeResolver($d);

        // Dedupe against punches already stored in the window (any source).
        // Identity = (bio id, punched_at) — direction stays OUT of the identity so a
        // re-sync can correct it in place (SmartPRS rev173g lesson: direction in the
        // key causes duplicate punches after any direction fix).
        $existing = BiometricLog::withoutGlobalScopes()
            ->where('company_id', $d->company_id)
            ->where('punched_at', '>=', $from)
            ->get(['id', 'biometric_employee_id', 'employee_id', 'punched_at', 'punch_type'])
            ->keyBy(fn ($l) => $l->biometric_employee_id . '|' . $l->punched_at->format('Y-m-d H:i:s'));

        $now = now();
        $inserts = [];
        $corrections = [];
        $dupes = 0;
        $corrected = 0;
        $unmapped = 0;
        $unmatchedCodes = [];
        $seen = [];

        foreach ($rows as $r) {
            $hit = $resolve($r['code']);
            $key = $hit['bio_id'] . '|' . $r['punched_at']->format('Y-m-d H:i:s');
            $ex = $existing->get($key);
            if ($ex) {
                if (in_array($r['direction'], ['IN', 'OUT'], true) && $ex->punch_type !== $r['direction']) {
                    BiometricLog::withoutGlobalScopes()->whereKey($ex->id)->update(['punch_type' => $r['direction']]);
                    $corrected++;
                    if ($ex->employee_id) {
                        $corrections[] = ['employee_id' => $ex->employee_id, 'punch_type' => $r['direction'], 'punched_at' => $r['punched_at']];
                    }
                }
                $dupes++;
                continue;
            }
            if (isset($seen[$key])) {
                $dupes++;
                continue;
            }
            $seen[$key] = true;
            if ($hit['employee_id'] === null) {
                $unmapped++;
                $unmatchedCodes[$hit['bio_id']] = true;
            }

            $inserts[] = [
                'company_id'            => $d->company_id,
                'biometric_device_id'   => $d->id,
                'biometric_employee_id' => $hit['bio_id'],
                'employee_id'           => $hit['employee_id'],
                'punch_type'            => $r['direction'],
                'punched_at'            => $r['punched_at'],
                'verification_mode'     => null,
                'raw_log_ref'           => $r['mc'] !== null ? ('MC:' . $r['mc']) : null,
                'processed'             => true,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        if ($inserts) {
            foreach (array_chunk($inserts, 500) as $chunk) {
                BiometricLog::insert($chunk);
            }
        }

        // A single reader used for BOTH directions decides IN/OUT from the employee's
        // punch sequence for that day — and that sequence lives in the database, not in
        // this batch, so it is settled after the rows are stored. Re-running it is safe:
        // the same day always produces the same answer.
        $resequenced = 0;
        if ($inserts && PunchDirectionResolver::mode($d) === 'IN_OUT') {
            [$inserts, $corrections, $resequenced] = $this->applyDaySequence($d, $inserts, $corrections);
        }

        if ($inserts || $corrections) {
            $touched = array_merge($inserts, $corrections);
            BiometricController::mergeIntoAttendance($d->company_id, $touched);
            // Biometric Gate v1.1: cloud-synced punches drive the gate/auto-break
            // engine exactly like pushed or imported ones.
            BiometricController::processGatePunches($d->company_id, $touched);
            // QA Phase 3 (B7/B8): shift-aware checkout + configurable late, same path.
            BiometricController::deriveAttendance($d->company_id, $touched);
        }

        $codes = array_slice(array_keys($unmatchedCodes), 0, 15);
        $msg = sprintf('OK — %d fetched, %d new, %d duplicate, %d corrected, %d unmapped', count($rows), count($inserts), $dupes, $corrected, $unmapped)
            . ($resequenced ? sprintf(', %d re-sequenced IN/OUT', $resequenced) : '')
            . ($codes ? ('. Unmatched: ' . implode(', ', $codes)) : '');
        $d->forceFill(['last_sync_at' => now(), 'last_sync_result' => mb_substr($msg, 0, 490)])->save();

        return [
            'ok'              => true,
            'message'         => $msg,
            'fetched'         => count($rows),
            'stored'          => count($inserts),
            'duplicate'       => $dupes,
            'corrected'       => $corrected,
            'resequenced'     => $resequenced,
            'unmapped'        => $unmapped,
            'unmatched_codes' => $codes,
        ];
    }

    /**
     * Ask the device's provider for punches, then apply the direction policy.
     * Probe and sync both go through here, so what Test connection previews is exactly
     * what a sync would store.
     */
    private function collect(BiometricDevice $d, Carbon $from, Carbon $to): array
    {
        $provider = $this->providers->for($d);

        $this->lastRaw = null;
        try {
            $rows = $provider->fetch($d, $from, $to);
        } finally {
            $this->lastRaw = $provider->lastRaw();
        }

        // IN only / OUT only / IN+OUT. AUTO leaves the provider's own answer alone.
        $rows = $this->directions->applyDeviceMode($d, $rows);

        return $this->alternateUndecided($rows);
    }

    /**
     * Undecided punches alternate IN → OUT per employee per day, in time order.
     * (Historically this lived at the end of the eTimeOffice fetch; it now runs for
     * every provider, which is what makes a reader that reports no direction usable.)
     */
    private function alternateUndecided(array $rows): array
    {
        $undecided = [];
        foreach ($rows as $i => $r) {
            if ($r['direction'] === null) {
                $undecided[$r['code'] . '|' . $r['punched_at']->toDateString()][] = $i;
            }
        }
        foreach ($undecided as $idx) {
            usort($idx, fn ($a, $b) => $rows[$a]['punched_at'] <=> $rows[$b]['punched_at']);
            foreach (array_values($idx) as $n => $i) {
                $rows[$i]['direction'] = $n % 2 === 0 ? 'IN' : 'OUT';
            }
        }

        return $rows;
    }

    /**
     * Settle an IN+OUT reader's directions against the employee's whole stored day, then
     * fold the result back into the in-memory batch so attendance, the gate and the
     * derivation all see the final answer. A punch that was already stored and only had
     * its direction flipped rides the existing "correction" path.
     *
     * @return array{0: array, 1: array, 2: int}  [inserts, corrections, changedCount]
     */
    private function applyDaySequence(BiometricDevice $d, array $inserts, array $corrections): array
    {
        $changed = $this->directions->resequenceForRows($d->company_id, $inserts);
        if (! $changed) {
            return [$inserts, $corrections, 0];
        }

        $byKey = [];
        foreach ($changed as $c) {
            $byKey[$c['employee_id'] . '|' . $c['punched_at']->format('Y-m-d H:i:s')] = $c['punch_type'];
        }

        foreach ($inserts as &$row) {
            if (($row['employee_id'] ?? null) === null) {
                continue;
            }
            $at = $row['punched_at'] instanceof Carbon ? $row['punched_at'] : Carbon::parse($row['punched_at']);
            $k = $row['employee_id'] . '|' . $at->format('Y-m-d H:i:s');
            if (isset($byKey[$k])) {
                $row['punch_type'] = $byKey[$k];
                unset($byKey[$k]);
            }
        }
        unset($row);

        // Anything still in $byKey is an EARLIER punch whose direction moved because this
        // batch changed the day's sequence — attendance has to be told about those too.
        foreach ($changed as $c) {
            $k = $c['employee_id'] . '|' . $c['punched_at']->format('Y-m-d H:i:s');
            if (isset($byKey[$k])) {
                $corrections[] = [
                    'employee_id' => (int) $c['employee_id'],
                    'punch_type'  => $c['punch_type'],
                    'punched_at'  => $c['punched_at'],
                ];
                unset($byKey[$k]);
            }
        }

        return [$inserts, $corrections, count($changed)];
    }

    /** Maps a feed employee code → SmartEPT employee, honouring the configured prefix. */
    private function employeeResolver(BiometricDevice $d): \Closure
    {
        $maps = BiometricEmployeeMapping::withoutGlobalScopes()
            ->where('company_id', $d->company_id)->where('active', true)
            ->pluck('employee_id', 'biometric_employee_id');

        $emps = Employee::withoutGlobalScopes()
            ->where('company_id', $d->company_id)
            ->get(['id', 'employee_code', 'biometric_id']);
        $byCode = $emps->filter(fn ($e) => filled($e->employee_code))->keyBy(fn ($e) => strtoupper($e->employee_code));
        $byBio = $emps->filter(fn ($e) => filled($e->biometric_id))->keyBy(fn ($e) => strtoupper($e->biometric_id));

        $prefix = trim((string) $d->employee_id_prefix);

        return function (string $code) use ($maps, $byCode, $byBio, $prefix): array {
            $candidates = array_values(array_unique(array_filter([
                $prefix !== '' ? $prefix . $code : null,
                $code,
            ], fn ($c) => $c !== null && $c !== '')));

            foreach ($candidates as $c) {
                if (isset($maps[$c])) {
                    return ['employee_id' => (int) $maps[$c], 'bio_id' => $c];
                }
                $u = strtoupper($c);
                if (isset($byCode[$u])) {
                    return ['employee_id' => (int) $byCode[$u]->id, 'bio_id' => $c];
                }
                if (isset($byBio[$u])) {
                    return ['employee_id' => (int) $byBio[$u]->id, 'bio_id' => $c];
                }
            }

            return ['employee_id' => null, 'bio_id' => $candidates[0] ?? $code];
        };
    }
}
