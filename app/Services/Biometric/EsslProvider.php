<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * eSSL eTimeTrackLite Web API — SOAP 1.1 GetTransactionsLog (Ejaz, 28-Aug-2026).
 *
 *   POST http://<host>:<port>/iclock/WebAPIService.asmx
 *   SOAPAction: "http://tempuri.org/GetTransactionsLog"
 *   <GetTransactionsLog>
 *     <FromDateTime/> <ToDateTime/> <SerialNumber/> <UserName/> <UserPassword/> <strDataList/>
 *   </GetTransactionsLog>
 *
 * ONE ROW PER PHYSICAL READER: the call is filtered by SerialNumber, so each reader is
 * its own device record with its own branch, floor and punch direction. Several readers
 * on one eTimeTrackLite server simply share the same URL and login.
 *
 * ⚠ The vendor manual (API v1.3, 24-Jan-2023) documents the REQUEST exactly but its
 * sample RESPONSE says only "We will Post data" — it never shows the punch payload, and
 * neither does the live server's WSDL page. So the response is read defensively:
 * whatever comes back in strDataList / GetTransactionsLogResult is run through
 * PunchPayload::rows(), which accepts JSON, an XML row set, or delimited text, and the
 * console's "Test connection" always prints the raw body when nothing parses. Send that
 * raw body over and the field mapping below is a five-minute change.
 *
 * Two request dialects are tried, because they disagree: the live server's WSDL uses
 * FromDateTime/ToDateTime while the manual's worked example uses FromDate/ToDate. Each
 * is tried with three date formats. The ladder stops at the first call that returns
 * punches, so a working server costs exactly one request.
 */
class EsslProvider implements PunchProvider
{
    public const KEY = 'ESSL';

    /** eTimeTrackLite's default Web API path, used only when the URL has no .asmx in it. */
    private const DEFAULT_PATH = '/iclock/WebAPIService.asmx';

    private const SOAP_ACTION = 'http://tempuri.org/GetTransactionsLog';

    private ?string $lastRaw = null;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'eSSL — eTimeTrackLite Web API';
    }

    public function lastRaw(): ?string
    {
        return $this->lastRaw;
    }

    public function fetch(BiometricDevice $d, Carbon $from, Carbon $to): array
    {
        if (blank($d->api_base_url)) {
            throw new RuntimeException('The eTimeTrackLite Web API URL is required (e.g. http://192.168.1.140:81/iclock/WebAPIService.asmx).');
        }
        if (blank($d->device_serial)) {
            throw new RuntimeException('The device serial number is required for eSSL — GetTransactionsLog is filtered by SerialNumber.');
        }

        $url = $this->url($d);
        $serial = trim((string) $d->device_serial);

        $rows = [];
        $firstRaw = null;
        $lastEx = null;

        // FromDateTime/ToDateTime is the live WSDL; FromDate/ToDate is the manual's example.
        foreach ([['FromDateTime', 'ToDateTime'], ['FromDate', 'ToDate']] as [$fromTag, $toTag]) {
            foreach (['Y/m/d H:i', 'Y-m-d H:i:s', 'd/m/Y H:i'] as $fmt) {
                try {
                    $payload = $this->call($d, $url, $fromTag, $toTag, $from->format($fmt), $to->format($fmt));
                } catch (RuntimeException $e) {
                    $lastEx = $e;
                    // Auth and transport problems are final — only a parameter/date
                    // complaint is worth retrying with the other dialect.
                    if (! $this->isRetryable($e->getMessage())) {
                        throw $e;
                    }

                    continue;
                }

                $firstRaw ??= $this->lastRaw;
                $rows = $this->normalize($payload, $serial);
                if ($rows) {
                    return $rows;
                }
            }
        }

        if ($firstRaw === null && $lastEx) {
            throw $lastEx;
        }

        // The server answered but nothing parsed — keep the body so the console can show it.
        $this->lastRaw = $firstRaw;

        return [];
    }

    /** Full endpoint URL. api_endpoint is honoured if set; otherwise the standard path is appended only when missing. */
    private function url(BiometricDevice $d): string
    {
        $url = rtrim(trim((string) $d->api_base_url), '/');

        if (filled($d->api_endpoint)) {
            return $url . '/' . ltrim(trim((string) $d->api_endpoint), '/');
        }

        return str_contains(strtolower($url), '.asmx') ? $url : $url . self::DEFAULT_PATH;
    }

    /** One SOAP call. Returns the payload string the service put in its response. */
    private function call(BiometricDevice $d, string $url, string $fromTag, string $toTag, string $fromStr, string $toStr): string
    {
        $this->lastRaw = null;

        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            . ' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><GetTransactionsLog xmlns="http://tempuri.org/">'
            . '<' . $fromTag . '>' . $this->x($fromStr) . '</' . $fromTag . '>'
            . '<' . $toTag . '>' . $this->x($toStr) . '</' . $toTag . '>'
            . '<SerialNumber>' . $this->x((string) $d->device_serial) . '</SerialNumber>'
            . '<UserName>' . $this->x((string) $d->api_username) . '</UserName>'
            . '<UserPassword>' . $this->x((string) $d->api_password) . '</UserPassword>'
            . '<strDataList></strDataList>'
            . '</GetTransactionsLog></soap:Body></soap:Envelope>';

        $res = Http::timeout(90)
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction'   => '"' . self::SOAP_ACTION . '"',
            ])
            ->withBody($envelope, 'text/xml; charset=utf-8')
            ->post($url);

        $body = (string) $res->body();
        $this->lastRaw = mb_substr($body, 0, 1500);

        // A SOAP fault still arrives as HTTP 500, and its text is the useful part.
        $fault = $this->faultString($body);
        if ($fault !== null) {
            throw new RuntimeException('eSSL rejected the request: ' . mb_substr($fault, 0, 300));
        }
        if ($res->failed()) {
            throw new RuntimeException('HTTP ' . $res->status() . ' from the eSSL Web API: ' . mb_substr($body, 0, 300));
        }

        $result = $this->element($body, 'GetTransactionsLogResult');
        $data = $this->element($body, 'strDataList');

        // The service reports a bad login as plain text in the result element, with HTTP
        // 200 and no fault — eTimeTrackLite 11.10 says exactly "Unathorised User" (sic,
        // its own spelling), so match the misspelling too or this reads as "0 punches".
        foreach ([$result, $data] as $t) {
            if (is_string($t) && preg_match('/una[u]?thori[sz]|authentication\s*fail|invalid\s*(user|password|credential)|not\s*authori[sz]ed/i', $t)) {
                // Name the username actually sent and how long the password was: a blank
                // password box means "keep the saved one", so the pair on screen is not
                // always the pair on the wire.
                throw new RuntimeException(sprintf(
                    'eSSL rejected the login ("%s"). Sent username "%s" with a %d-character password. '
                    . 'Use the eTimeTrackLite WEB API login (it is not always the application login), and make '
                    . 'sure that user has Web API permission granted in eTimeTrackLite.',
                    trim($t),
                    (string) $d->api_username,
                    mb_strlen((string) $d->api_password)
                ));
            }
        }

        // Punches normally ride in strDataList; some builds put them in the result element.
        $payload = (string) ($data ?? '');

        return trim($payload) !== '' ? $payload : (string) ($result ?? '');
    }

    /** Turn parsed records into normalized punch rows for this reader. */
    private function normalize(string $payload, string $serial): array
    {
        $rows = [];

        foreach (PunchPayload::rows($payload) as $rec) {
            if (! is_array($rec)) {
                continue;
            }

            $row = array_is_list($rec) ? $this->fromPositional($rec) : $this->fromAssoc($rec);
            if ($row === null) {
                continue;
            }

            // Defensive: if the payload carries a serial and it is not this reader's, drop it.
            if ($row['mc'] !== null && $this->looksLikeSerial($row['mc']) && strcasecmp($row['mc'], $serial) !== 0) {
                continue;
            }
            $row['mc'] ??= $serial;

            $rows[] = $row;
        }

        return $rows;
    }

    private function fromAssoc(array $rec): ?array
    {
        $code = trim((string) PunchPayload::pick($rec, [
            'EmployeeCode', 'EmpCode', 'Empcode', 'employee_code', 'EmployeeId', 'EmpId',
            'UserId', 'UserID', 'USERID', 'EnrollNumber', 'EnrollNo', 'PIN', 'BadgeNumber', 'badgenumber',
        ]));

        $dtRaw = PunchPayload::pick($rec, [
            'DateTime', 'LogDate', 'LogDateTime', 'PunchDate', 'PunchDateTime', 'DeviceLogDate',
            'TransactionDate', 'AttDateTime', 'RecordTime', 'CHECKTIME', 'punch_time', 'punched_at',
        ]);

        if ($code === '' || blank($dtRaw)) {
            return null;
        }
        $when = PunchPayload::parseWhen((string) $dtRaw);
        if (! $when) {
            return null;
        }

        $mc = PunchPayload::pick($rec, ['SerialNumber', 'DeviceSerial', 'SN', 'MachineNumber', 'MachineNo', 'MachineId', 'DeviceId']);
        $name = PunchPayload::pick($rec, ['EmployeeName', 'EmpName', 'Name', 'UserName', 'FullName']);
        $flag = PunchPayload::pick($rec, ['INOUT', 'InOut', 'InOutMode', 'PunchDirection', 'Direction', 'IOMode', 'C1', 'CHECKTYPE', 'Status']);

        return [
            'code'       => $code,
            'name'       => is_string($name) ? trim($name) : null,
            'punched_at' => $when,
            'mc'         => ($mc === null || $mc === '') ? null : trim((string) $mc),
            'direction'  => $this->direction($flag),
        ];
    }

    /**
     * Positional row (delimited text with no header). Nothing is assumed about column
     * order beyond "one field is the timestamp": the first parsable date/time is the
     * punch, the first non-empty field before it is the employee code, and any field
     * that looks like a device serial is kept as the machine reference.
     */
    private function fromPositional(array $parts): ?array
    {
        $when = null;
        $whenIdx = null;
        foreach ($parts as $i => $p) {
            if (PunchPayload::looksLikeDateTime((string) $p) && ($w = PunchPayload::parseWhen((string) $p))) {
                $when = $w;
                $whenIdx = $i;
                break;
            }
        }
        if (! $when) {
            return null;
        }

        $code = '';
        foreach ($parts as $i => $p) {
            if ($i === $whenIdx) {
                break;
            }
            if (trim((string) $p) !== '') {
                $code = trim((string) $p);
                break;
            }
        }
        if ($code === '') {
            return null;
        }

        $mc = null;
        foreach ($parts as $i => $p) {
            if ($i !== $whenIdx && $this->looksLikeSerial((string) $p)) {
                $mc = trim((string) $p);
                break;
            }
        }

        return [
            'code'       => $code,
            'name'       => null,
            'punched_at' => $when,
            'mc'         => $mc,
            'direction'  => null,
        ];
    }

    /** eTimeTrackLite mostly reports no direction at all — the device's punch direction mode decides. */
    private function direction($flag): ?string
    {
        $f = strtoupper(trim((string) $flag));
        if ($f === '') {
            return null;
        }
        if (in_array($f, ['IN', 'I', '0', 'CHECKIN', 'CHECK IN', 'ENTRY'], true)) {
            return 'IN';
        }
        if (in_array($f, ['OUT', 'O', '1', 'CHECKOUT', 'CHECK OUT', 'EXIT'], true)) {
            return 'OUT';
        }

        return null;
    }

    /** eSSL serials look like CUB7244600978 / BRM9202760325 — letters then digits, no separators. */
    private function looksLikeSerial(string $s): bool
    {
        return (bool) preg_match('/^[A-Za-z]{2,4}\d{6,}$/', trim($s));
    }

    private function isRetryable(string $message): bool
    {
        return (bool) preg_match('/parameter|element|missing|datetime|parse|format|invalid\s*date|server was unable/i', $message);
    }

    /** Text of a SOAP faultstring, or null when the body is not a fault. */
    private function faultString(string $body): ?string
    {
        if (! preg_match('/<(?:\w+:)?Fault[\s>]/i', $body)) {
            return null;
        }
        $t = $this->element($body, 'faultstring');

        return $t !== null ? $t : 'SOAP fault';
    }

    /** Contents of the first <name>…</name> element, CDATA and entities resolved. */
    private function element(string $xml, string $name): ?string
    {
        if (! preg_match('~<(?:[\w.-]+:)?' . preg_quote($name, '~') . '\b[^>]*>(.*?)</(?:[\w.-]+:)?' . preg_quote($name, '~') . '>~is', $xml, $m)) {
            return null;
        }

        $v = $m[1];
        if (preg_match('/^\s*<!\[CDATA\[(.*)\]\]>\s*$/s', $v, $c)) {
            return $c[1];
        }

        // An escaped XML/JSON document arrives entity-encoded; a nested one does not.
        return html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function x(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
