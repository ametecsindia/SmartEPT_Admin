<?php

namespace App\Services;

use App\Http\Controllers\Api\BiometricController;
use App\Models\BiometricDevice;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricLog;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cloud biometric punch import (Ejaz 17-Jul): pulls punches from a cloud
 * attendance API (eTimeOffice-style: Basic auth over corporate:user:password,
 * GET {base}/{endpoint}?Empcode=ALL&FromDate=..&ToDate=..) and feeds them into
 * the SAME pipeline as middleware push / CSV import: BiometricLog rows +
 * attendance merge — so imported punches mark PRESENT, feed payroll and open
 * the Gate-to-PC wall exactly like a locally-pushed punch.
 *
 * IN/OUT resolution (per the console form): the configured IN/OUT machine
 * numbers OVERRIDE the feed's own flag; otherwise the feed's IN/OUT flag is
 * used; with neither, punches alternate IN → OUT per employee per day.
 */
class BiometricCloudSync
{
    /** First 1500 chars of the last provider response body (for zero-parse debugging). */
    private ?string $lastRaw = null;

    /** Test connection: fetch today's punches, store NOTHING. */
    public function probe(BiometricDevice $d): array
    {
        try {
            $rows = $this->fetch($d, now()->subDay(), now());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'sample' => []];
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
            . ($mcs->isNotEmpty() ? ('. MC numbers seen: ' . $mcs->implode(', ')) : '');

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
            $rows = $this->fetch($d, $from, now());
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
            . ($codes ? ('. Unmatched: ' . implode(', ', $codes)) : '');
        $d->forceFill(['last_sync_at' => now(), 'last_sync_result' => mb_substr($msg, 0, 490)])->save();

        return [
            'ok'              => true,
            'message'         => $msg,
            'fetched'         => count($rows),
            'stored'          => count($inserts),
            'duplicate'       => $dupes,
            'corrected'       => $corrected,
            'unmapped'        => $unmapped,
            'unmatched_codes' => $codes,
        ];
    }

    /** Call the provider and return normalized punch rows (code, punched_at, mc, direction). */
    private function fetch(BiometricDevice $d, Carbon $from, Carbon $to): array
    {
        if (blank($d->api_base_url) || blank($d->api_endpoint)) {
            throw new RuntimeException('API base URL and endpoint are required.');
        }

        $url = rtrim($d->api_base_url, '/') . '/' . ltrim($d->api_endpoint, '/');

        // eTimeOffice vendor quirk (SmartPRS rev156-173g, production-proven):
        // HTTP Basic auth where the USERNAME is the whole "CorpID:Username:Password:true"
        // string and the password is the password again.
        $basicUser = filled($d->corporate_id)
            ? ($d->corporate_id . ':' . $d->api_username . ':' . $d->api_password . ':true')
            : (string) $d->api_username;

        // eTimeOffice's ParseExact wants dd/MM/yyyy_HH:mm — underscore, NO seconds
        // (SmartPRS-proven). Fall back automatically on a provider DateTime parse error.
        $list = null;
        $lastEx = null;
        foreach (['d/m/Y_H:i', 'd/m/Y_H:i:s', 'd/m/Y'] as $fmt) {
            try {
                $list = $this->requestPunchList($d, $url, $basicUser, $from->format($fmt), $to->format($fmt));
                break;
            } catch (RuntimeException $e) {
                $lastEx = $e;
                if (! preg_match('/DateTime|ParseExact|FormatException/i', $e->getMessage())) {
                    throw $e;
                }
            }
        }
        if ($list === null) {
            throw new RuntimeException(($lastEx ? $lastEx->getMessage() : 'Provider request failed.')
                . ' [tried FromDate as ' . $from->format('d/m/Y_H:i') . ', with seconds, and date-only]');
        }

        $rows = [];
        foreach ($list as $p) {
            if (! is_array($p)) {
                continue;
            }
            if (array_is_list($p)) {
                // Positional row: [emp, name, datetime, inout, machineNo] (SmartPRS-proven).
                $p = ['Empcode' => $p[0] ?? null, 'Name' => $p[1] ?? null, 'DateTime' => $p[2] ?? null,
                      'INOUT' => $p[3] ?? null, 'MachineNo' => $p[4] ?? null];
            }
            $code = trim((string) $this->pick($p, ['Empcode', 'EmpCode', 'empcode', 'Code', 'EmployeeCode', 'employee_code', 'EmpId']));
            $dtRaw = $this->pick($p, ['DateTime', 'PunchDateTime', 'PunchDate', 'punched_at', 'punch_time', 'DateTimeStr']);
            if ($code === '' || blank($dtRaw)) {
                continue;
            }
            $when = $this->parseWhen(trim((string) $dtRaw));
            if (! $when) {
                continue;
            }
            $mc = $this->pick($p, ['MC', 'MCID', 'MCNo', 'MC_No', 'McId', 'MachineNo', 'MachineNumber', 'MachineId', 'machine_no', 'DeviceId']);
            $mc = ($mc === null || $mc === '') ? null : trim((string) $mc);
            $flag = $this->pick($p, ['INOUT', 'InOut', 'PunchDirection', 'Direction', 'IO', 'Status']);
            $name = $this->pick($p, ['Name', 'EmpName', 'EmployeeName', 'FullName']);

            $rows[] = [
                'code'       => $code,
                'name'       => is_string($name) ? trim($name) : null,
                'punched_at' => $when,
                'mc'         => $mc,
                'direction'  => $this->direction($d, $mc, $flag),
            ];
        }

        // Undecided punches alternate IN → OUT per employee per day, in time order.
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

    /** One provider call with a specific FromDate/ToDate string; returns the raw punch list. */
    private function requestPunchList(BiometricDevice $d, string $url, string $basicUser, string $fromStr, string $toStr): array
    {
        $this->lastRaw = null;
        $res = Http::timeout(60)
            ->withBasicAuth($basicUser, (string) $d->api_password)
            ->withHeaders(['Accept' => 'application/json'])
            ->get($url, [
                'Empcode'  => filled($d->employee_code_filter) ? $d->employee_code_filter : 'ALL',
                'FromDate' => $fromStr,
                'ToDate'   => $toStr,
            ]);

        if ($res->failed()) {
            throw new RuntimeException('HTTP ' . $res->status() . ' from provider: ' . mb_substr($res->body(), 0, 300));
        }

        $this->lastRaw = mb_substr((string) $res->body(), 0, 1500);
        $json = $res->json();
        if (! is_array($json)) {
            throw new RuntimeException('Provider did not return JSON: ' . mb_substr((string) $res->body(), 0, 200));
        }

        // eTimeOffice signals failure as Error:true + Msg, or Error:"text".
        $err = $json['Error'] ?? null;
        if ($err === true || $err === 'true' || (is_string($err) && trim($err) !== '')) {
            $msg = $json['Msg'] ?? $json['Message'] ?? $json['msg'] ?? (is_string($err) ? $err : null);
            throw new RuntimeException('Provider rejected the request: '
                . (is_string($msg) && $msg !== '' ? $msg : json_encode($json)));
        }

        foreach (['PunchData', 'InOutPunchData', 'Data', 'data', 'punches', 'logs'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                return $json[$k];
            }
        }

        return array_is_list($json) ? $json : [];
    }

    /** Machine number overrides the feed flag; feed flag next; null = decide later. */
    private function direction(BiometricDevice $d, ?string $mc, $flag): ?string
    {
        if ($mc !== null && filled($d->in_machine_id) && strcasecmp($mc, trim($d->in_machine_id)) === 0) {
            return 'IN';
        }
        if ($mc !== null && filled($d->out_machine_id) && strcasecmp($mc, trim($d->out_machine_id)) === 0) {
            return 'OUT';
        }

        $f = strtoupper(trim((string) $flag));
        if (in_array($f, ['IN', 'I', '0'], true)) {
            return 'IN';
        }
        if (in_array($f, ['OUT', 'O', '1'], true)) {
            return 'OUT';
        }

        return null;
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

    private function pick(array $row, array $keys)
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                return $row[$k];
            }
        }

        // Case-insensitive fallback — vendors vary key casing (MCID/McId/mcid...).
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        foreach ($keys as $k) {
            $lk = strtolower($k);
            if (isset($lower[$lk]) && $lower[$lk] !== '') {
                return $lower[$lk];
            }
        }

        return null;
    }

    /** Providers send dd/MM/yyyy most often — try day-first formats before anything else. */
    private function parseWhen(string $s): ?Carbon
    {
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'j/n/Y H:i:s', 'j/n/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $s);
            } catch (\Throwable) {
                // try next format
            }
        }
        try {
            return Carbon::parse(str_replace('/', '-', $s)); // dd-mm-yyyy parses day-first
        } catch (\Throwable) {
            return null;
        }
    }
}
