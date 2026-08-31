<?php

namespace App\Services\Biometric;

use Illuminate\Support\Carbon;
use SimpleXMLElement;

/**
 * Shared, vendor-agnostic helpers for reading a punch feed.
 *
 * pick() and parseWhen() are the ORIGINAL BiometricCloudSync helpers, moved here so
 * both providers use exactly the same field-name and date-format tolerance that
 * eTimeOffice has been running on since July. rows() is new: it turns whatever a
 * SOAP/ASMX service hands back — JSON, an XML row set, or delimited text — into a
 * list of records, because eTimeTrackLite's own manual never documents the payload.
 */
class PunchPayload
{
    /** Value of the first key that is present and non-empty, case-insensitively. */
    public static function pick(array $row, array $keys)
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
    public static function parseWhen(string $s): ?Carbon
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        foreach ([
            'd/m/Y H:i:s', 'd/m/Y H:i', 'j/n/Y H:i:s', 'j/n/Y H:i',
            'd-m-Y H:i:s', 'd-m-Y H:i',
            'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i',
            'Y/m/d H:i:s', 'Y/m/d H:i',
            'd-M-Y H:i:s', 'd-M-Y H:i', 'd/m/Y h:i:s A', 'm/d/Y h:i:s A',
        ] as $fmt) {
            try {
                $c = Carbon::createFromFormat($fmt, $s);
                if ($c) {
                    return $c;
                }
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

    /** Does this string look like a punch timestamp? Used to read positional rows. */
    public static function looksLikeDateTime(string $s): bool
    {
        return (bool) preg_match('/\d{1,4}[\/\-]\d{1,2}[\/\-]\d{1,4}.*\d{1,2}:\d{2}/', trim($s));
    }

    /**
     * Turn a raw payload into a list of records (each an assoc array, or a positional
     * list). Handles the three shapes an ASMX punch service realistically returns:
     * JSON, an XML row set, or delimited lines (tab / pipe / comma / semicolon).
     *
     * @return array<int, array>
     */
    public static function rows(?string $payload): array
    {
        $raw = trim((string) $payload);
        if ($raw === '') {
            return [];
        }

        if (str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
            $rows = self::fromJson($raw);
            if ($rows) {
                return $rows;
            }
        }

        if (str_starts_with($raw, '<')) {
            $rows = self::fromXml($raw);
            if ($rows) {
                return $rows;
            }
        }

        return self::fromDelimited($raw);
    }

    /** @return array<int, array> */
    private static function fromJson(string $raw): array
    {
        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return [];
        }
        if (array_is_list($json)) {
            return array_values(array_filter($json, 'is_array'));
        }
        // {"Data":[...]} / {"PunchData":[...]} / {"Table":[...]} — take the first list of rows.
        foreach ($json as $v) {
            if (is_array($v) && array_is_list($v) && $v && is_array($v[0])) {
                return $v;
            }
        }

        return [$json];
    }

    /**
     * Pull row elements out of an XML document. A row is any element whose children are
     * all leaves — that matches a DataSet <Table>, a <Record>, an <InOutPunchData> item
     * and anything else shaped like a table, without hard-coding a vendor's tag name.
     *
     * @return array<int, array>
     */
    private static function fromXml(string $raw): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $xml = new SimpleXMLElement($raw, LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (\Throwable) {
            libxml_use_internal_errors($prev);

            return [];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $rows = [];
        self::walkXml($xml, $rows);

        return $rows;
    }

    private static function walkXml(SimpleXMLElement $node, array &$rows): void
    {
        $children = $node->children();
        if (count($children) === 0) {
            return;
        }

        $leafOnly = true;
        foreach ($children as $c) {
            if (count($c->children()) > 0) {
                $leafOnly = false;
                break;
            }
        }

        if ($leafOnly) {
            $row = [];
            foreach ($children as $name => $c) {
                $row[(string) $name] = trim((string) $c);
            }
            if (count($row) >= 2) {
                $rows[] = $row;
            }

            return;
        }

        foreach ($children as $c) {
            self::walkXml($c, $rows);
        }
    }

    /**
     * Delimited text: one punch per line. Uses a header row when there is one, otherwise
     * hands back positional lists for the caller to interpret.
     *
     * @return array<int, array>
     */
    private static function fromDelimited(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
        if (! $lines) {
            return [];
        }

        $delim = self::delimiter($lines[0]);
        if ($delim === null) {
            return [];
        }

        $split = fn (string $l) => array_map('trim', explode($delim, $l));

        $first = $split($lines[0]);
        $isHeader = ! self::rowHasDateTime($first)
            && (bool) preg_match('/emp|user|code|date|time|name|serial/i', $lines[0]);

        $header = null;
        if ($isHeader) {
            $header = array_map(fn ($h) => trim($h), $first);
            array_shift($lines);
        }

        $rows = [];
        foreach ($lines as $l) {
            $parts = $split($l);
            if (count($parts) < 2) {
                continue;
            }
            if ($header) {
                $rows[] = array_combine(
                    array_slice(array_pad($header, count($parts), ''), 0, count($parts)),
                    $parts
                ) ?: $parts;
            } else {
                $rows[] = $parts;
            }
        }

        return $rows;
    }

    private static function delimiter(string $line): ?string
    {
        foreach (["\t", '|', ';', ','] as $d) {
            if (substr_count($line, $d) >= 1) {
                return $d;
            }
        }

        return null;
    }

    private static function rowHasDateTime(array $parts): bool
    {
        foreach ($parts as $p) {
            if (self::looksLikeDateTime((string) $p)) {
                return true;
            }
        }

        return false;
    }
}
