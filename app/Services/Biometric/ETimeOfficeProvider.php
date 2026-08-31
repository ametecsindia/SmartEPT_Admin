<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * eTimeOffice-style cloud attendance API (Ejaz 17-Jul-2026).
 *
 * This class is the ORIGINAL BiometricCloudSync::fetch moved out unchanged when the
 * second provider (eSSL) arrived on 28-Aug-2026 — same auth quirk, same date-format
 * fallback ladder, same field-name candidates, same IN/OUT rule. Nothing about how an
 * eTimeOffice device behaves changed; only where the code lives.
 *
 * Auth quirk (SmartPRS rev156-173g, production-proven): HTTP Basic where the USERNAME
 * is the whole "CorpID:Username:Password:true" string and the password is the password
 * again.
 */
class ETimeOfficeProvider implements PunchProvider
{
    public const KEY = 'ETIMEOFFICE';

    private ?string $lastRaw = null;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'eTimeOffice (cloud attendance API)';
    }

    public function lastRaw(): ?string
    {
        return $this->lastRaw;
    }

    /** Call the provider and return normalized punch rows (code, name, punched_at, mc, direction). */
    public function fetch(BiometricDevice $d, Carbon $from, Carbon $to): array
    {
        if (blank($d->api_base_url) || blank($d->api_endpoint)) {
            throw new RuntimeException('API base URL and endpoint are required.');
        }

        $url = rtrim($d->api_base_url, '/') . '/' . ltrim($d->api_endpoint, '/');

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
            $code = trim((string) PunchPayload::pick($p, ['Empcode', 'EmpCode', 'empcode', 'Code', 'EmployeeCode', 'employee_code', 'EmpId']));
            $dtRaw = PunchPayload::pick($p, ['DateTime', 'PunchDateTime', 'PunchDate', 'punched_at', 'punch_time', 'DateTimeStr']);
            if ($code === '' || blank($dtRaw)) {
                continue;
            }
            $when = PunchPayload::parseWhen(trim((string) $dtRaw));
            if (! $when) {
                continue;
            }
            $mc = PunchPayload::pick($p, ['MC', 'MCID', 'MCNo', 'MC_No', 'McId', 'MachineNo', 'MachineNumber', 'MachineId', 'machine_no', 'DeviceId']);
            $mc = ($mc === null || $mc === '') ? null : trim((string) $mc);
            $flag = PunchPayload::pick($p, ['INOUT', 'InOut', 'PunchDirection', 'Direction', 'IO', 'Status']);
            $name = PunchPayload::pick($p, ['Name', 'EmpName', 'EmployeeName', 'FullName']);

            $rows[] = [
                'code'       => $code,
                'name'       => is_string($name) ? trim($name) : null,
                'punched_at' => $when,
                'mc'         => $mc,
                'direction'  => $this->direction($d, $mc, $flag),
            ];
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
}
