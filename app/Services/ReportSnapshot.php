<?php

namespace App\Services;

/**
 * 30-Sep-2026 (Ejaz): the Productivity report as ONE image for WhatsApp — the same overview
 * cards as the email plus the by-employee table. Drawn with PHP's GD (bundled with PHP /
 * Laragon), so a client server needs nothing extra installed. Fonts: Segoe UI / Arial on
 * Windows, DejaVu / Liberation on Linux; GD's built-in font if none is found.
 */
class ReportSnapshot
{
    private const W = 1080;
    private const MAX_ROWS = 40;

    private $im;
    private ?string $font = null;
    private ?string $bold = null;
    private array $c = [];

    /** PNG bytes. $rows = ProductivityController rows (the FULL report). */
    public function png(array $rows, string $label, string $company, string $title): string
    {
        $by = [];
        foreach ($rows as $r) {
            $id = $r['employee_id'];
            $by[$id] ??= ['name' => $r['name'] ?? '', 'work' => 0, 'idle' => 0, 'break' => 0, 'away' => 0, 'prod' => 0, 'net' => 0];
            foreach (['work' => 'work_seconds', 'idle' => 'idle_seconds', 'break' => 'break_seconds', 'away' => 'away_seconds',
                'prod' => 'productive_seconds', 'net' => 'net_working_seconds'] as $k => $col) {
                $by[$id][$k] += (int) ($r[$col] ?? 0);
            }
        }
        $by = array_values($by);
        usort($by, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $shown = array_slice($by, 0, self::MAX_ROWS);

        $h = 150 + 150 + 60 + 44 * max(1, count($shown)) + (count($by) > self::MAX_ROWS ? 44 : 0) + 70;
        $this->im = imagecreatetruecolor(self::W, $h);
        $this->fonts();
        $col = fn ($hex) => imagecolorallocate($this->im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        $this->c = ['bg' => $col('#FFFFFF'), 'navy' => $col('#052A33'), 'teal' => $col('#0E7C8F'), 'ink' => $col('#0F1E26'),
            'ink2' => $col('#4A5A66'), 'line' => $col('#E5E1D8'), 'alt' => $col('#FAF9F5'), 'sub' => $col('#9FC3CB'),
            'white' => $col('#FFFFFF'), 'warn' => $col('#B7791F')];
        imagefilledrectangle($this->im, 0, 0, self::W, $h, $this->c['bg']);

        // Header band.
        imagefilledrectangle($this->im, 0, 0, self::W, 120, $this->c['navy']);
        $this->text(40, 58, 'Productivity report — ' . $label, 30, 'white', true);
        $this->text(40, 98, $company . ' · ' . $title, 19, 'sub');

        // Overview cards.
        $sum = fn ($k) => (int) array_sum(array_column($by, $k));
        $prod = $sum('prod');
        $net = $sum('net');
        $cards = [
            ['Employees', (string) count($by), '#0C3B49'], ['Working', ScheduledReports::hm($sum('work')), '#16A34A'],
            ['Idle', ScheduledReports::hm($sum('idle')), '#D97706'], ['Break', ScheduledReports::hm($sum('break')), '#6366F1'],
            ['Away', ScheduledReports::hm($sum('away')), '#DC2626'], ['Productive', ScheduledReports::pct($prod, $net), '#16A34A'],
        ];
        $cw = (self::W - 80 - 5 * 12) / 6;
        foreach ($cards as $i => [$l, $v, $hex]) {
            $x = (int) (40 + $i * ($cw + 12));
            imagerectangle($this->im, $x, 150, (int) ($x + $cw), 260, $this->c['line']);
            imagefilledrectangle($this->im, $x, 150, (int) ($x + $cw), 155, $col($hex));
            $this->text($x + 14, 190, $l, 16, 'ink2');
            $this->text($x + 14, 238, $v, 25, 'ink', true);
        }

        // By-employee table.
        $y = 300;
        $cols = [['Employee', 40], ['Working', 420], ['Idle', 550], ['Break', 670], ['Away', 790], ['Productive', 900]];
        imagefilledrectangle($this->im, 40, $y, self::W - 40, $y + 44, $this->c['teal']);
        foreach ($cols as [$t, $x]) {
            $this->text($x + 12, $y + 29, $t, 17, 'white', true);
        }
        $y += 44;
        foreach ($shown as $i => $e) {
            if ($i % 2) {
                imagefilledrectangle($this->im, 40, $y, self::W - 40, $y + 44, $this->c['alt']);
            }
            $active = ($e['work'] + $e['idle']) > 0;
            $pct = $active ? ScheduledReports::pct($e['prod'], $e['net']) : 'No activity';
            $low = $active && $e['net'] > 0 && $e['prod'] / $e['net'] < 0.6;
            $cells = [mb_strimwidth($e['name'], 0, 34, '…'), ScheduledReports::hm($e['work']), ScheduledReports::hm($e['idle']),
                ScheduledReports::hm($e['break']), ScheduledReports::hm($e['away']), $pct];
            foreach ($cols as $j => [, $x]) {
                $this->text($x + 12, $y + 29, $cells[$j], 17, $j === 5 && ($low || ! $active) ? ($low ? 'warn' : 'ink2') : 'ink', $j === 5 && $active);
            }
            imageline($this->im, 40, $y + 44, self::W - 40, $y + 44, $this->c['line']);
            $y += 44;
        }
        if (! $shown) {
            $this->text(52, $y + 29, 'No activity was recorded in this period.', 17, 'ink2');
            $y += 44;
        }
        if (count($by) > self::MAX_ROWS) {
            $this->text(52, $y + 29, '+ ' . (count($by) - self::MAX_ROWS) . ' more — the full list is in the emailed report.', 17, 'ink2');
        }
        $this->text(40, $h - 28, 'SmartEPT · sent automatically (Reports → Schedule Report)', 14, 'ink2');

        ob_start();
        imagepng($this->im);
        imagedestroy($this->im);

        return (string) ob_get_clean();
    }

    private function fonts(): void
    {
        $pairs = [
            [resource_path('fonts/report.ttf'), resource_path('fonts/report-bold.ttf')],
            ['C:\\Windows\\Fonts\\segoeui.ttf', 'C:\\Windows\\Fonts\\segoeuib.ttf'],
            ['C:\\Windows\\Fonts\\arial.ttf', 'C:\\Windows\\Fonts\\arialbd.ttf'],
            ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
            ['/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'],
        ];
        if (! function_exists('imagettftext')) {
            return;
        }
        foreach ($pairs as [$r, $b]) {
            if (@is_file($r)) {
                $this->font = $r;
                $this->bold = @is_file($b) ? $b : $r;

                return;
            }
        }
    }

    private function text(int $x, int $y, string $s, int $px, string $color, bool $bold = false): void
    {
        if ($this->font) {
            imagettftext($this->im, $px * 0.75, 0, $x, $y, $this->c[$color], $bold ? $this->bold : $this->font, $s);
        } else {
            // ponytail: GD's bitmap font has no Unicode — ASCII-only fallback when no TTF exists.
            imagestring($this->im, 5, $x, $y - 14, preg_replace('/[^\x20-\x7E]/', '-', $s), $this->c[$color]);
        }
    }
}
