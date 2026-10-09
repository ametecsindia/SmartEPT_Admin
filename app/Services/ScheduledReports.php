<?php

namespace App\Services;

use App\Http\Controllers\Api\ProductivityController;
use App\Models\Employee;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Support\ResolvesLocalNow;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 30-Sep-2026 (Ejaz): Reports → Schedule Report. Emails the Productivity report on a schedule:
 *  - OWN  — each chosen employee (or every active employee in scope) gets THEIR report: the
 *           summary, and for a daily report the day event by event (sign-ins, breaks, idle,
 *           away, door), plus their rows as .xlsx;
 *  - FULL — chosen people and extra addresses get the whole report for the scope, with an
 *           overview and the same .xlsx as Reports & Exports.
 * The rows come from ProductivityController::rowsFor(), so an email always matches the screen.
 * Data is bounded by the scope of the admin who created the schedule.
 */
class ScheduledReports
{
    use ResolvesLocalNow;

    public const KIND = 'scheduled_report';
    private const FULL_TABLE_CAP = 300;
    /** 07-Oct-2026 (Ejaz): these roles get the whole company in the Reporting team's report. */
    public const TEAM_ADMIN_ROLES = ['COMPANY_ADMIN', 'HR_ADMIN', 'BRANCH_ADMIN'];
    /** 08-Oct-2026: rows above this go into the interactive (AMP) part only up to this many — Gmail caps it at 200 KB. */
    private const AMP_ROW_CAP = 400;
    /** Max rows in each "Show only" list of the report email (keeps it under Gmail's ~100 KB clip). */
    private const VIEW_ROWS = 30;
    /** 08-Oct-2026 (Ejaz): late login counts from 10 minutes in the report email. */
    private const LATE_MIN = 10;
    /** How many names in each of the Top / Bottom employee columns. */
    private const TOP_N = 5;

    /** Is the schedule due at this company-local moment? Once per local date. */
    public static function isDue(ReportSchedule $s, Carbon $local): bool
    {
        if (! $s->enabled || $local->format('H:i') < (string) $s->send_time || $s->last_period === $local->toDateString()) {
            return false;
        }
        $dow = $local->dayOfWeekIso;

        return match ($s->frequency) {
            'WEEKLY'  => (int) (($s->days ?: [1])[0]) === $dow,
            'MONTHLY' => $local->day === max(1, min(28, (int) $s->day_of_month)),
            default   => in_array($dow, array_map('intval', $s->days ?: [1, 2, 3, 4, 5, 6, 7]), true),
        };
    }

    /** [from, to] a send on $local covers: the previous day / previous 7 days / previous month. */
    public static function period(ReportSchedule $s, Carbon $local): array
    {
        $d = $local->copy()->startOfDay();

        return match ($s->frequency) {
            'WEEKLY'  => [$d->copy()->subDays(7)->toDateString(), $d->copy()->subDay()->toDateString()],
            'MONTHLY' => [$d->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                          $d->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default   => [$d->copy()->subDay()->toDateString(), $d->copy()->subDay()->toDateString()],
        };
    }

    /** Scheduler entry point (every minute): send every schedule that is due, on its company's clock. */
    public function runDue(): int
    {
        $n = 0;
        foreach (ReportSchedule::withoutGlobalScopes()->where('enabled', true)->get() as $s) {
            $local = $this->localNow($s->company_id);
            if (! self::isDue($s, $local)) {
                continue;
            }
            // Claim the day first, so a slow or failing run can never send twice.
            $s->forceFill(['last_period' => $local->toDateString()])->save();
            try {
                $this->run($s, $local);
            } catch (\Throwable $e) {
                $s->forceFill(['last_run_at' => now(), 'last_status' => 'Failed: ' . mb_substr($e->getMessage(), 0, 400)])->save();
            }
            $n++;
        }

        return $n;
    }

    /**
     * Send one schedule for the period ending yesterday. $onlyTo = test: the full report and one
     * sample employee report go to that address only, and nothing is recorded on the schedule.
     * @return array{sent:int, failed:int, skipped:int, text:string}
     */
    public function run(ReportSchedule $s, ?Carbon $local = null, ?string $onlyTo = null): array
    {
        return $this->onCompanyClock($s->company_id, function () use ($s, $local, $onlyTo) {
            $local ??= $this->localNow($s->company_id);
            [$from, $to] = self::period($s, $local);
            $label = $from === $to ? Carbon::parse($from)->format('D, d M Y')
                : Carbon::parse($from)->format('d M') . ' – ' . Carbon::parse($to)->format('d M Y');
            $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'text' => ''];
            $finish = function (string $text) use (&$out, $s, $onlyTo) {
                $out['text'] = $text;
                if (! $onlyTo) {
                    $s->forceFill(['last_run_at' => now(), 'last_status' => mb_substr($text, 0, 500)])->save();
                }

                return $out;
            };

            if ($s->skip_holidays && $from === $to && ! $onlyTo
                && app(WorkCalendar::class)->isHoliday((int) $s->company_id, $from)) {
                return $finish('Skipped — ' . $label . ' was a company holiday');
            }
            $user = $this->runAs($s);
            if (! $user) {
                return $finish('Not sent — no active admin account to run the report as');
            }

            $req = Request::create('/api/reports/productivity', 'GET', ['from' => $from, 'to' => $to]);
            $req->setUserResolver(fn () => $user);
            $pc = app(ProductivityController::class);
            // Completed days are read from the nightly summaries; build any that are missing
            // (a send before 00:30, or a night the summary job did not run). Idempotent.
            try { $pc->rebuildSummaries($req); } catch (\Throwable $e) { /* report what exists */ }
            $rows = $pc->rowsFor($req)[2];
            $byEmp = collect($rows)->groupBy('employee_id');

            $scopeIds = $this->scopeIds($s);
            $fullRows = $scopeIds === null ? $rows : array_values(array_filter($rows, fn ($r) => in_array($r['employee_id'], $scopeIds, true)));

            $rec = collect($s->recipients ?: [])->filter(fn ($r) => ! empty($r['employee_id']))->keyBy(fn ($r) => (int) $r['employee_id']);
            $ownIds = $rec->filter(fn ($r) => ($r['mode'] ?? 'OWN') === 'OWN')->keys()->all();
            if ($s->all_employees_own) {
                $ownIds = array_values(array_unique(array_merge($ownIds, Employee::withoutGlobalScopes()
                    ->where('company_id', $s->company_id)->where('employment_status', 'ACTIVE')
                    ->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds))->pluck('id')->all())));
            }
            $fullIds = $rec->filter(fn ($r) => ($r['mode'] ?? 'OWN') === 'FULL')->keys()->all();
            $emps = Employee::withoutGlobalScopes()->where('company_id', $s->company_id)
                ->whereIn('id', array_merge($ownIds, $fullIds))->with('user:id,email')->get()->keyBy('id');
            $emailOf = fn (Employee $e) => trim((string) ($rec[$e->id]['email'] ?? '')) ?: trim((string) ($e->email ?: $e->user?->email));
            $company = (string) (\App\Models\Company::withoutGlobalScopes()->whereKey($s->company_id)->value('name') ?: 'SmartEPT');
            $track = function (string $st) use (&$out) { $out[$st === 'sent' ? 'sent' : ($st === 'failed' ? 'failed' : 'skipped')]++; };
            $noData = 0;

            // FULL report — chosen people + extra addresses.
            $fullTo = [];
            foreach ($fullIds as $id) {
                if (isset($emps[$id]) && ($m = $emailOf($emps[$id]))) {
                    $fullTo[strtolower($m)] = trim($emps[$id]->first_name . ' ' . $emps[$id]->last_name);
                }
            }
            foreach ((array) ($s->extra_emails ?: []) as $m) {
                if (filter_var(trim((string) $m), FILTER_VALIDATE_EMAIL)) {
                    $fullTo[strtolower(trim($m))] ??= 'Team';
                }
            }
            if ($onlyTo) {
                $fullTo = [$onlyTo => 'Team'];
            }
            if ($fullTo) {
                if (! array_filter($fullRows, [self::class, 'hasActivity']) && $s->skip_empty && ! $onlyTo) {
                    $out['skipped'] += count($fullTo);
                    $noData += count($fullTo);
                } else {
                    $files = $this->xlsx($s, $fullRows, 'SmartEPT-Productivity-Report-' . $from . '_' . $to . '.xlsx');
                    $audience = $onlyTo ? array_keys($this->fullAudience($fullIds, $emps, $emailOf, $s)) : null;
                    foreach ($fullTo as $m => $who) {
                        // 30-Sep-2026 (Ejaz): {name}/{date}… in subject + message filled per recipient.
                        $subject = $this->subject($s, 'Productivity report — ' . $label, $label, $who, $company);
                        $html = $this->fullHtml($s, $fullRows, $label, $company, $audience, $who);
                        $track(MailService::sendHtml($m, ($onlyTo ? '[TEST] ' : '') . $subject, $html, $files, self::KIND, (int) $s->company_id,
                            $this->ampHtml('Productivity report — ' . $label, $company . ' · ' . $s->name, $fullRows)));
                    }
                }
            }

            // OWN — each employee their own report.
            foreach ($ownIds as $id) {
                $e = $emps[$id] ?? null;
                if (! $e) {
                    continue;
                }
                $mine = ($byEmp[$id] ?? collect())->values()->all();
                if (! array_filter($mine, [self::class, 'hasActivity']) && ($s->skip_empty || $onlyTo)) {
                    if (! $onlyTo) {
                        $out['skipped']++;
                        $noData++;
                    }
                    continue;
                }
                $to1 = $onlyTo ?: $emailOf($e);
                $name = trim($e->first_name . ' ' . $e->last_name);
                $subject = $this->subject($s, 'Your productivity report — ' . $label, $label, $name, $company);
                $html = $this->ownHtml($s, $e, $mine, $from, $to, $label, $company,
                    $onlyTo ? ('This is a test. It would go to ' . ($emailOf($e) ?: 'nobody — no email on file')) : null);
                $track(MailService::sendHtml($to1, ($onlyTo ? '[TEST] ' : '') . $subject, $html,
                    $this->xlsx($s, $mine, 'My-Productivity-' . $from . '_' . $to . '.xlsx'), self::KIND, (int) $s->company_id));
                if ($onlyTo) {
                    break; // one sample is enough for a test
                }
            }

            // 07-Oct-2026 (Ejaz): Reporting team's productivity. Each reporting manager (Employees →
            // Reporting Manager) gets only the employees who report to them; Company / HR / Branch
            // Admins get the whole company. Anyone already on the full-report list is not mailed twice.
            $teamN = 0;
            if ($s->team_reports) {
                $teamTo = []; // email => [name, rows, isTeam]
                foreach (User::withoutGlobalScopes()->where('company_id', $s->company_id)->where('status', 'ACTIVE')
                    ->whereHas('role', fn ($q) => $q->whereIn('slug', self::TEAM_ADMIN_ROLES))->orderBy('id')->get(['id', 'name', 'email']) as $u) {
                    $m = strtolower(trim((string) $u->email));
                    if (filter_var($m, FILTER_VALIDATE_EMAIL)) {
                        $teamTo[$m] = [$u->name, $rows, false];
                    }
                }
                $teams = [];
                foreach (Employee::withoutGlobalScopes()->where('company_id', $s->company_id)->where('employment_status', 'ACTIVE')
                    ->whereNotNull('reporting_manager_user_id')->get(['id', 'reporting_manager_user_id']) as $e) {
                    $teams[(int) $e->reporting_manager_user_id][] = (int) $e->id;
                }
                foreach (User::withoutGlobalScopes()->where('company_id', $s->company_id)->where('status', 'ACTIVE')
                    ->whereIn('id', array_keys($teams))->orderBy('id')->get(['id', 'name', 'email']) as $u) {
                    $m = strtolower(trim((string) $u->email));
                    if (filter_var($m, FILTER_VALIDATE_EMAIL) && ! isset($teamTo[$m])) {
                        $ids = $teams[$u->id];
                        $teamTo[$m] = [$u->name, array_values(array_filter($rows, fn ($r) => in_array((int) $r['employee_id'], $ids, true))), true];
                    }
                }
                if ($onlyTo) {
                    // Test: one sample, a manager's team report if there is one.
                    $pick = collect($teamTo)->filter(fn ($x) => $x[2])->keys()->first() ?? array_key_first($teamTo);
                    $teamTo = $pick ? [$onlyTo => $teamTo[$pick] + [3 => $pick]] : [];
                } else {
                    $teamTo = array_diff_key($teamTo, $fullTo);
                }
                foreach ($teamTo as $m => $x) {
                    [$who, $tr, $isTeam] = $x;
                    if (! array_filter($tr, [self::class, 'hasActivity']) && $s->skip_empty && ! $onlyTo) {
                        $out['skipped']++;
                        $noData++;
                        continue;
                    }
                    $title = $isTeam ? "Reporting team's productivity" : 'Productivity report';
                    $subject = $this->subject($s, $title . ' — ' . $label, $label, $who, $company);
                    $html = $this->fullHtml($s, $tr, $label, $company, $onlyTo ? [$x[3]] : null, $who, $title,
                        $isTeam ? 'Employees reporting to ' . $who : 'Whole company');
                    $track(MailService::sendHtml($m, ($onlyTo ? '[TEST] ' : '') . $subject, $html,
                        $this->xlsx($s, $tr, 'SmartEPT-' . ($isTeam ? 'Team' : 'Productivity') . '-Report-' . $from . '_' . $to . '.xlsx'), self::KIND, (int) $s->company_id,
                        $this->ampHtml($title . ' — ' . $label, $company . ' · ' . ($isTeam ? 'Employees reporting to ' . $who : 'Whole company'), $tr)));
                    $teamN++;
                }
            }

            // 30-Sep-2026 (Ejaz): the full-report snapshot as an image on WhatsApp — to the
            // Company Admin(s) and/or the numbers typed on the schedule. Not in "test to me" mode
            // (that is email only; the WhatsApp card has its own test).
            $wa = '';
            if ($s->whatsapp_enabled && ! $onlyTo) {
                $wa = $this->sendWhatsApp($s, $fullRows, $label, $company);
            }

            $text = $out['sent'] . ' sent · ' . $out['failed'] . ' failed · ' . $out['skipped'] . ' skipped'
                . ($noData ? ' (' . $noData . ' had no activity)' : '') . ($teamN ? ' · ' . $teamN . ' reporting-team' : '') . ' — covers ' . $label;
            if ($out['sent'] + $out['failed'] + $out['skipped'] === 0) {
                $text = ($wa ? 'No emails' : 'Nobody to send to') . ' — covers ' . $label;
            }
            $text .= $wa;

            return $finish(($onlyTo ? 'Test to ' . $onlyTo . ': ' : '') . $text);
        });
    }

    /** The WhatsApp image for the period ending yesterday (console preview). */
    public function snapshotPng(ReportSchedule $s): string
    {
        return $this->snapshotParts($s)[0];
    }

    /** @return array{0:string,1:string,2:array} [png, period label, full rows] for the period ending yesterday. */
    public function snapshotParts(ReportSchedule $s): array
    {
        return $this->onCompanyClock($s->company_id, function () use ($s) {
            $local = $this->localNow($s->company_id);
            [$from, $to] = self::period($s, $local);
            $label = $from === $to ? Carbon::parse($from)->format('D, d M Y')
                : Carbon::parse($from)->format('d M') . ' – ' . Carbon::parse($to)->format('d M Y');
            $user = $this->runAs($s);
            $rows = [];
            if ($user) {
                $req = Request::create('/api/reports/productivity', 'GET', ['from' => $from, 'to' => $to]);
                $req->setUserResolver(fn () => $user);
                $pc = app(ProductivityController::class);
                try { $pc->rebuildSummaries($req); } catch (\Throwable $e) { /* report what exists */ }
                $rows = $pc->rowsFor($req)[2];
                $ids = $this->scopeIds($s);
                if ($ids !== null) {
                    $rows = array_values(array_filter($rows, fn ($r) => in_array($r['employee_id'], $ids, true)));
                }
            }
            $company = (string) (\App\Models\Company::withoutGlobalScopes()->whereKey($s->company_id)->value('name') ?: 'SmartEPT');

            return [app(ReportSnapshot::class)->png($rows, $label, $company, $s->name), $label, $rows];
        });
    }

    /** WhatsApp numbers for a schedule: typed numbers + (optionally) the Company Admins' phones. */
    public static function whatsappNumbers(ReportSchedule $s): array
    {
        $raw = (array) ($s->whatsapp_numbers ?: []);
        if ($s->whatsapp_admins) {
            $raw = array_merge($raw, User::withoutGlobalScopes()->where('company_id', $s->company_id)->where('status', 'ACTIVE')
                ->whereHas('role', fn ($q) => $q->where('slug', 'COMPANY_ADMIN'))->whereNotNull('phone')->pluck('phone')->all());
        }

        return array_values(array_unique(array_filter(array_map(fn ($n) => WhatsAppSender::normalise((string) $n), $raw))));
    }

    /** One-line summary for the WhatsApp template body {{2}}. */
    public static function summaryLine(array $rows): string
    {
        $by = collect($rows)->groupBy('employee_id');
        $prod = self::sum($rows, 'productive_seconds');
        $net = self::sum($rows, 'net_working_seconds');
        $active = $by->filter(fn ($g) => array_filter($g->all(), [self::class, 'hasActivity']))->count();
        $low = $by->filter(function ($g) {
            $n = self::sum($g->all(), 'net_working_seconds');

            return $n > 0 && self::sum($g->all(), 'productive_seconds') / $n < 0.6;
        })->count();

        return $by->count() . ' employees · ' . $active . ' active · ' . self::pct($prod, $net) . ' productive'
            . ($low ? ' · ' . $low . ' below 60%' : '');
    }

    private function sendWhatsApp(ReportSchedule $s, array $rows, string $label, string $company): string
    {
        $nums = self::whatsappNumbers($s);
        if (! $nums) {
            return ' · WhatsApp: no numbers';
        }
        if (! WhatsAppSender::connected((int) $s->company_id)) {
            return ' · WhatsApp: not connected';
        }
        if ($s->skip_empty && ! array_filter($rows, [self::class, 'hasActivity'])) {
            return ' · WhatsApp: skipped (no activity)';
        }
        try {
            $png = app(ReportSnapshot::class)->png($rows, $label, $company, $s->name);
        } catch (\Throwable $e) {
            return ' · WhatsApp: image failed (' . mb_substr($e->getMessage(), 0, 80) . ')';
        }
        $ok = $bad = 0;
        foreach ($nums as $n) {
            app(WhatsAppSender::class)->sendImage((int) $s->company_id, $n, $png, $label . ' · ' . $s->name, self::summaryLine($rows)) === 'sent' ? $ok++ : $bad++;
        }

        return ' · WhatsApp: ' . $ok . ' sent' . ($bad ? ', ' . $bad . ' failed (see mail log)' : '');
    }

    /** A day-row with anything recorded (the nightly summary also writes empty rows for absent days). */
    public static function hasActivity(array $r): bool
    {
        return ! empty($r['first_in']) || ((int) ($r['present_seconds'] ?? 0) + (int) ($r['work_seconds'] ?? 0) + (int) ($r['idle_seconds'] ?? 0)) > 0;
    }

    /** The creator (while active, same company), else the company's first active Company Admin. */
    private function runAs(ReportSchedule $s): ?User
    {
        $u = $s->created_by ? User::withoutGlobalScopes()->with('role')->find($s->created_by) : null;
        if ($u && (int) $u->company_id === (int) $s->company_id && ($u->status ?? 'ACTIVE') === 'ACTIVE') {
            return $u;
        }

        return User::withoutGlobalScopes()->with('role')->where('company_id', $s->company_id)->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->where('slug', 'COMPANY_ADMIN'))->orderBy('id')->first();
    }

    /** Employee ids of the schedule's branch/department/team filter; null = whole company. */
    private function scopeIds(ReportSchedule $s): ?array
    {
        $f = array_filter(array_intersect_key((array) ($s->scope ?: []), array_flip(['branch_id', 'department_id', 'team_id'])));
        if (! $f) {
            return null;
        }

        return Employee::withoutGlobalScopes()->where('company_id', $s->company_id)
            ->where(function ($q) use ($f) { foreach ($f as $col => $v) { $q->where($col, (int) $v); } })
            ->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    private function fullAudience(array $fullIds, $emps, callable $emailOf, ReportSchedule $s): array
    {
        $a = [];
        foreach ($fullIds as $id) {
            if (isset($emps[$id]) && ($m = $emailOf($emps[$id]))) {
                $a[$m] = true;
            }
        }
        foreach ((array) ($s->extra_emails ?: []) as $m) {
            $a[$m] = true;
        }

        return $a;
    }

    private function subject(ReportSchedule $s, string $default, string $period, string $name, string $company): string
    {
        $t = trim((string) $s->subject);

        return $t === '' ? $default : self::fill($t, $name, $period, $company, $s->name);
    }

    /**
     * 30-Sep-2026 (Ejaz): placeholders an admin types in the Subject / Message, filled at send time.
     * Either {…} or <…>, any case: {name} / <Employee Full Name> / <Employee Name> / <Full Name>,
     * {first_name}, {date} / {period}, {company}, {schedule}. Unknown text is left as typed.
     */
    public static function fill(string $text, string $name, string $period, string $company, string $schedule): string
    {
        $first = trim(strtok($name, ' ') ?: $name);
        $map = [
            'name' => $name, 'employee name' => $name, 'employee full name' => $name, 'full name' => $name, 'employee' => $name,
            'first name' => $first, 'first_name' => $first,
            'date' => $period, 'period' => $period, 'report date' => $period,
            'company' => $company, 'company name' => $company, 'schedule' => $schedule,
        ];

        return preg_replace_callback('/[{<]\s*([a-z_ ]{2,30}?)\s*[}>]/i', function ($m) use ($map) {
            $k = strtolower(preg_replace('/\s+/', ' ', $m[1]));

            return $map[$k] ?? $m[0];
        }, $text);
    }

    /** @return array<int, array{0:string,1:string,2:string}> */
    private function xlsx(ReportSchedule $s, array $rows, string $name): array
    {
        if (! $s->attach_excel || ! $rows || ! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return [];
        }
        $w = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx(app(ProductivityController::class)->spreadsheet($rows));
        ob_start();
        $w->save('php://output');

        return [[ob_get_clean(), $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
    }

    // ---- HTML ----

    public static function hm($s): string
    {
        $s = max(0, (int) $s);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        return $h ? $h . 'h ' . $m . 'm' : $m . 'm';
    }

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private static function sum(array $rows, string $k): int
    {
        return (int) array_sum(array_map(fn ($r) => (int) ($r[$k] ?? 0), $rows));
    }

    public static function pct(int $v, int $of): string
    {
        return $of > 0 ? round($v / $of * 100) . '%' : '—';
    }

    private function shell(string $company, string $title, string $sub, ?string $message, string $inner, ?string $banner = null): string
    {
        $note = trim((string) $message) !== '' ? '<p style="margin:0 0 14px;padding:10px 12px;background:#F1F7F8;border-left:3px solid #0E7C8F;white-space:pre-wrap">' . self::e($message) . '</p>' : '';
        $ban = $banner ? '<p style="margin:0 0 14px;padding:8px 12px;background:#FFF7E0;border:1px solid #F0D48A;border-radius:6px">' . self::e($banner) . '</p>' : '';

        return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:13px;color:#0F1E26;max-width:900px">'
            . '<div style="background:#052A33;color:#fff;padding:14px 18px;border-radius:8px 8px 0 0">'
            . '<div style="font-size:16px;font-weight:700">' . self::e($title) . '</div>'
            . '<div style="font-size:12px;color:#9FC3CB;margin-top:2px">' . self::e($company) . ' · ' . self::e($sub) . '</div></div>'
            . '<div style="border:1px solid #E5E1D8;border-top:none;padding:16px 18px;border-radius:0 0 8px 8px">' . $ban . $note . $inner
            . '<p style="margin:18px 0 0;font-size:11px;color:#8494A0">Sent automatically by SmartEPT (Reports → Schedule Report). Times are in the company\'s time zone.</p>'
            . '</div></div>';
    }

    private static function cards(array $cards): string
    {
        $h = '<table cellpadding="0" cellspacing="6" style="margin:0 -6px 12px"><tr>';
        foreach ($cards as [$l, $v, $c]) {
            $h .= '<td style="border:1px solid #E5E1D8;border-top:3px solid ' . $c . ';border-radius:6px;padding:8px 12px;min-width:92px">'
                . '<div style="font-size:11px;color:#4A5A66">' . self::e($l) . '</div><div style="font-size:16px;font-weight:700">' . self::e($v) . '</div></td>';
        }

        return $h . '</tr></table>';
    }

    private static function table(string $title, array $head, array $rows, ?string $id = null, array $links = []): string
    {
        if (! $rows) {
            return '';
        }
        $h = ($title !== '' ? '<div style="font-weight:700;margin:14px 0 6px">' . ($id ? '<a id="' . $id . '" name="' . $id . '"></a>' : '') . self::e($title) . '</div>' : '')
            . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:12px"><tr>';
        foreach ($head as $i => $c) {
            $h .= '<th style="background:#0E7C8F;color:#fff;text-align:left;font-weight:600">' . (isset($links[$i])
                ? '<a href="#' . $links[$i] . '" style="color:#fff;text-decoration:underline">' . self::e($c) . '</a>' : self::e($c)) . '</th>';
        }
        $h .= '</tr>';
        foreach ($rows as $i => $r) {
            $h .= '<tr style="background:' . ($i % 2 ? '#FAF9F5' : '#fff') . '">';
            foreach ($r as $c) {
                // [text, true] = out-of-limit value in red (productivity < 60%, late > LATE_MIN, …)
                $red = is_array($c) && ! empty($c[1]);
                $c = is_array($c) ? $c[0] : $c;
                $h .= ($red ? '<td style="color:#D92D20;font-weight:700">' : '<td>') . self::e($c ?? '—') . '</td>';
            }
            $h .= '</tr>';
        }

        return $h . '</table>';
    }

    private function totalsCards(array $rows): string
    {
        $prod = self::sum($rows, 'productive_seconds');
        $net = self::sum($rows, 'net_working_seconds');

        return self::cards([
            ['Present', self::hm(self::sum($rows, 'present_seconds')), '#0C3B49'],
            ['Working', self::hm(self::sum($rows, 'work_seconds')), '#16A34A'],
            ['Meeting', self::hm(self::sum($rows, 'meeting_seconds')), '#0E7C8F'],
            ['Idle', self::hm(self::sum($rows, 'idle_seconds')), '#D97706'],
            ['Break', self::hm(self::sum($rows, 'break_seconds')), '#6366F1'],
            ['Away', self::hm(self::sum($rows, 'away_seconds')), '#DC2626'],
            ['Productive', self::hm($prod) . ' · ' . self::pct($prod, $net), '#16A34A'],
        ]);
    }

    private function ownHtml(ReportSchedule $s, Employee $e, array $rows, string $from, string $to, string $label, string $company, ?string $banner): string
    {
        $name = trim($e->first_name . ' ' . $e->last_name);
        $msg = trim((string) $s->message) !== '' ? self::fill((string) $s->message, $name, $label, $company, $s->name) : null;
        $inner = ($msg ? '' : '<p style="margin:0 0 12px">Hi ' . self::e($e->first_name ?: $name) . ', here is your productivity report for <b>' . self::e($label) . '</b>.</p>')
            . $this->totalsCards($rows);

        $inner .= self::table($from === $to ? 'Summary' : 'Day by day',
            ['Date', 'Logged in', 'Logged out', 'Present', 'Working', 'Idle', 'Breaks', 'Meeting', 'Away', 'Productive', 'Productive %', 'Late (min)'],
            array_map(fn ($r) => [
                Carbon::parse($r['work_date'])->format('D d M'), $r['first_in'] ?? '—', $r['last_out'] ?? '—',
                self::hm($r['present_seconds'] ?? 0), self::hm($r['work_seconds'] ?? 0), self::hm($r['idle_seconds'] ?? 0),
                ((int) ($r['break_count'] ?? 0)) . ' · ' . self::hm($r['break_seconds'] ?? 0), self::hm($r['meeting_seconds'] ?? 0),
                self::hm($r['away_seconds'] ?? 0), self::hm($r['productive_seconds'] ?? 0),
                $r['productivity'] === null ? '—' : round((float) $r['productivity']) . '%', (int) ($r['late_minutes'] ?? 0),
            ], $rows));

        if ($from === $to) {
            try {
                $d = DayTimeline::build($e, $from);
                $t5 = fn ($x) => $x ? substr((string) $x, 0, 5) : '—';
                $inner .= '<div style="font-weight:700;font-size:14px;margin:18px 0 0;color:#052A33">Your day, event by event</div>'
                    . self::table('PC sign-ins', ['#', 'Signed in', 'Signed out', 'Reason'],
                        array_map(fn ($x) => [$x['n'], $t5($x['sign_in']), $t5($x['sign_out']), $x['reason'] ?? ''], $d['sessions']))
                    . self::table('Breaks', ['#', 'Type', 'From', 'To', 'Duration'],
                        array_map(fn ($x) => [$x['n'], $x['type'], $t5($x['from']), $t5($x['to']), self::hm($x['seconds'])], $d['breaks']))
                    . self::table('Idle', ['#', 'From', 'To', 'Back to active', 'Counted'],
                        array_map(fn ($x) => [$x['n'], $t5($x['from']), $t5($x['to']), $t5($x['returned_to_active']), self::hm($x['counted_seconds'])], $d['idle']))
                    . self::table('Away (out without a break)', ['#', 'Out', 'In', 'Back at desk', 'Away'],
                        array_map(fn ($x) => [$x['n'], $t5($x['out']), $t5($x['in']), $t5($x['back_at_desk'] ?? null), self::hm($x['away_seconds'])], $d['away']))
                    . self::table('Door punches', ['#', 'IN', 'OUT'],
                        array_map(fn ($x) => [$x['n'], $t5($x['in']), $t5($x['out'])], $d['door']));
            } catch (\Throwable $ex) {
                // The summary above is still right; the event log is a bonus.
            }
        }

        return $this->shell($company, 'Your productivity report — ' . $label, $name . ($e->employee_code ? ' (' . $e->employee_code . ')' : ''), $msg, $inner, $banner);
    }

    private function fullHtml(ReportSchedule $s, array $rows, string $label, string $company, ?array $testAudience, string $who = 'Team',
                              string $title = 'Productivity report', ?string $sub = null): string
    {
        $by = self::byEmployee($rows);

        $inner = '<p style="margin:0 0 12px"><b>' . $by->count() . '</b> employee' . ($by->count() === 1 ? '' : 's') . ' · <b>'
            . count($rows) . '</b> day-row' . (count($rows) === 1 ? '' : 's') . ' · ' . self::e($s->name) . '</p>'
            . ($rows ? $this->totalsCards($rows) : '<p>No activity was recorded in this period.</p>');

        $low = $by->filter(fn ($x) => $x['net'] > 0 && $x['prod'] / $x['net'] < 0.6)->count();
        if ($low) {
            $inner .= '<p style="margin:0 0 8px;color:#B7791F">' . $low . ' employee' . ($low === 1 ? ' is' : 's are') . ' below 60% productive.</p>';
        }
        // 08-Oct-2026 (Ejaz): "Show only" lists inside the email, for EVERY mail app, no setup.
        // Mail apps block scripts, so each filter is a ready-made list in the same email; the links
        // at the top and the column headings jump to it. Limits are per day (multi-day reports too).
        $head = ['Employee', 'Department', 'Days', 'Working', 'Idle', 'Break', 'Break exceeded', 'Away', 'Productive', 'Productive %', 'Late (min)'];
        $links = [0 => 'v-all', 1 => 'v-dept', 3 => 'v-work', 4 => 'v-idle', 6 => 'v-break', 9 => 'v-low', 10 => 'v-late'];
        $act = fn ($x) => ($x['work'] + $x['idle']) > 0;
        $pc = fn ($x) => $act($x) && $x['net'] > 0 ? $x['prod'] / $x['net'] * 100 : null;
        $isLow = fn ($x) => $pc($x) !== null && $pc($x) < 60;                        // productivity below 60%
        $isShort = fn ($x) => $act($x) && $x['work'] / max(1, $x['days']) < 6 * 3600;  // working under 6 h a day
        $isIdle = fn ($x) => $x['idle'] / max(1, $x['days']) > 3600;                  // idle over 1 h a day
        $isBreak = fn ($x) => $x['exc_days'] > 0;                                     // break exceeded by over 5 min
        $isLate = fn ($x) => $x['late_days'] > 0;                                     // late login over LATE_MIN min
        $line = fn ($x) => [$x['name'], $x['dept'], $x['days'], [self::hm($x['work']), $isShort($x)], [self::hm($x['idle']), $isIdle($x)],
            self::hm($x['break']), [$x['exceed'] ? self::hm($x['exceed']) : '—', $isBreak($x)], self::hm($x['away']), self::hm($x['prod']),
            [$act($x) ? self::pct($x['prod'], $x['net']) : 'No activity', $isLow($x)], [$x['late'], $isLate($x)]];
        // 08-Oct-2026 (Ejaz): every table lists the highest productivity first (no activity last).
        $cap = $by->take(self::FULL_TABLE_CAP)->sortByDesc(fn ($x) => $pc($x) ?? -1)->values();
        $only = fn ($f) => $cap->filter($f)->values();
        $views = [
            ['v-low', 'Productivity below 60%', $only($isLow)],
            ['v-break', 'Break exceeded (over 5 min)', $only($isBreak)],
            ['v-work', 'Working time under 6 hours', $only($isShort)],
            ['v-late', 'Late login (over ' . self::LATE_MIN . ' min)', $only($isLate)],
            ['v-idle', 'Idle time over 1 hour', $only($isIdle)],
        ];
        // Top / Bottom employees side by side, above the filters and tables.
        $ranked = $cap->filter(fn ($x) => $pc($x) !== null)->values();
        $col = function ($title, $list, $color) use ($pc) {
            $h = '<td valign="top" width="50%" style="padding:0 6px"><div style="border:1px solid #E5E1D8;border-top:3px solid ' . $color . ';border-radius:6px;padding:8px 12px">'
                . '<div style="font-weight:700;margin-bottom:4px">' . self::e($title) . '</div><table cellpadding="3" cellspacing="0" style="width:100%;font-size:12px">';
            foreach ($list as $i => $x) {
                $h .= '<tr><td style="color:#8494A0;width:18px">' . ($i + 1) . '.</td><td>' . self::e($x['name'])
                    . ($x['dept'] ? ' <span style="color:#8494A0">· ' . self::e($x['dept']) . '</span>' : '') . '</td>'
                    . '<td align="right" style="font-weight:700;color:' . ($pc($x) < 60 ? '#D92D20' : '#16A34A') . '">' . round($pc($x)) . '%</td></tr>';
            }

            return $h . ($list ? '' : '<tr><td style="color:#8494A0">No activity</td></tr>') . '</table></div></td>';
        };
        $n = min(self::TOP_N, intdiv($ranked->count() + 1, 2)); // never list the same person in both columns
        $inner .= '<table cellpadding="0" cellspacing="0" style="width:100%;margin:6px -6px 4px"><tr>'
            . $col('Top employees — highest productivity', $ranked->take($n)->all(), '#16A34A')
            . $col('Bottom employees — lowest productivity', array_reverse(array_slice($ranked->all(), max($n, $ranked->count() - $n))), '#D92D20')
            . '</tr></table>';
        $nav = '<a id="v-top" name="v-top"></a><p style="margin:14px 0 4px;font-size:12px;line-height:2"><b>Show only:</b> ';
        foreach ($views as [$id, $t, $v]) {
            $nav .= $v->isEmpty()
                ? '<span style="color:#8494A0;margin-right:12px">' . self::e($t) . ' (0)</span>'
                : '<a href="#' . $id . '" style="color:#D92D20;font-weight:600;margin-right:12px">' . self::e($t) . ' (' . $v->count() . ')</a>';
        }
        $depts = $cap->groupBy(fn ($x) => $x['dept'] ?: '—')->sortKeys();
        $nav .= '<br><b>View:</b> <a href="#v-all" style="color:#0E7C8F;margin-right:12px">All employees (' . $cap->count() . ')</a>'
            . '<a href="#v-dept" style="color:#0E7C8F">Department summary (' . $depts->count() . ')</a></p>';
        $inner .= $nav;
        $back = '<p style="margin:4px 0 0;font-size:11px"><a href="#v-top" style="color:#8494A0">&#8593; back to the list of filters</a></p>';
        foreach ($views as [$id, $t, $v]) {
            $inner .= $v->isEmpty() ? '' : self::table($t . ' — ' . $v->count() . ' employee' . ($v->count() === 1 ? '' : 's')
                . ($v->count() > self::VIEW_ROWS ? ' (first ' . self::VIEW_ROWS . ' shown; all are in "All employees")' : ''),
                $head, $v->take(self::VIEW_ROWS)->map($line)->all(), $id, $links) . $back;
        }
        $inner .= self::table('All employees — highest productivity first', $head, $cap->map($line)->all(), 'v-all', $links) . $back;
        $inner .= self::table('Department summary', ['Department', 'Employees', 'Working', 'Productive %', 'Below 60%', 'Break exceeded', 'Under 6 h', 'Late'],
            $depts->map(function ($v, $d) use ($isLow, $isBreak, $isShort, $isLate) {
                $p = (int) array_sum(array_column($v->all(), 'prod'));
                $n = (int) array_sum(array_column($v->all(), 'net'));
                $c = fn ($f) => $v->filter($f)->count();

                return [$d, $v->count(), self::hm(array_sum(array_column($v->all(), 'work'))), [self::pct($p, $n), $n > 0 && $p / $n < 0.6],
                    [$c($isLow), $c($isLow) > 0], [$c($isBreak), $c($isBreak) > 0], [$c($isShort), $c($isShort) > 0], [$c($isLate), $c($isLate) > 0]];
            })->values()->all(), 'v-dept') . $back;
        if ($by->count() > self::FULL_TABLE_CAP) {
            $inner .= '<p style="color:#4A5A66">Showing the first ' . self::FULL_TABLE_CAP . ' — every row is in the attached Excel.</p>';
        } elseif ($rows && $s->attach_excel) {
            $inner .= '<p style="color:#4A5A66">The full day-wise report is attached as Excel (same columns as Reports &amp; Exports).</p>';
        }

        $banner = $testAudience !== null ? 'This is a test. It would go to: ' . ($testAudience ? implode(', ', $testAudience) : 'nobody (no full-report recipients yet)') : null;

        $msg = trim((string) $s->message) !== '' ? self::fill((string) $s->message, $who, $label, $company, $s->name) : null;

        return $this->shell($company, $title . ' — ' . $label, $sub ? $sub . ' · ' . $s->name : $s->name, $msg, $inner, $banner);
    }

    /** One line per employee — the "By employee" table of the team / full email. */
    private static function byEmployee(array $rows): \Illuminate\Support\Collection
    {
        return collect($rows)->groupBy('employee_id')->map(function ($rs) {
            $a = $rs->all();

            return [
                'name' => $a[0]['name'] ?? '', 'dept' => $a[0]['department'] ?? '', 'days' => count($a),
                'work' => self::sum($a, 'work_seconds'), 'idle' => self::sum($a, 'idle_seconds'), 'break' => self::sum($a, 'break_seconds'),
                'away' => self::sum($a, 'away_seconds'), 'prod' => self::sum($a, 'productive_seconds'), 'net' => self::sum($a, 'net_working_seconds'),
                'late' => self::sum($a, 'late_minutes'), 'exceed' => self::sum($a, 'break_exceed_seconds'),
                'exc_days' => count(array_filter($a, fn ($r) => ($r['break_exceed_seconds'] ?? 0) > 300)),
                'late_days' => count(array_filter($a, fn ($r) => ($r['late_minutes'] ?? 0) > self::LATE_MIN)),
            ];
        })->sortBy('name')->values();
    }

    /**
     * 08-Oct-2026 (Ejaz): filter + sort INSIDE the email. Mail apps strip JavaScript, so this is the
     * AMP-for-Email part (text/x-amp-html): search, department, below-60% / late-only filters and
     * click-to-sort headings, all from the rows embedded in the email (no server calls, no links).
     * Gmail (web + app) shows it; every other mail app shows the normal HTML part instead.
     * Gmail only renders it for a sender registered with Google (or whitelisted in the recipient's
     * Gmail → Settings → Dynamic email → Developer settings) with SPF + DKIM + DMARC passing.
     */
    public function ampHtml(string $title, string $sub, array $rows): ?string
    {
        $by = self::byEmployee($rows)->take(self::AMP_ROW_CAP);
        if ($by->isEmpty()) {
            return null;
        }
        $data = $by->map(function ($x) {
            $act = ($x['work'] + $x['idle']) > 0 && $x['net'] > 0;
            $pct = $act ? (int) round($x['prod'] / $x['net'] * 100) : -1;

            return [
                'name' => (string) $x['name'], 'dept' => (string) ($x['dept'] ?: '—'), 'k' => mb_strtolower($x['name'] . ' ' . $x['dept']),
                'n' => mb_strtolower((string) $x['name']), 'd' => mb_strtolower((string) $x['dept']), 'days' => (int) $x['days'],
                'work' => (int) $x['work'], 'idle' => (int) $x['idle'], 'brk' => (int) $x['break'], 'away' => (int) $x['away'], 'prod' => (int) $x['prod'],
                'pct' => $pct, 'late' => (int) $x['late'],
                'workT' => self::hm($x['work']), 'idleT' => self::hm($x['idle']), 'brkT' => self::hm($x['break']), 'awayT' => self::hm($x['away']),
                'prodT' => self::hm($x['prod']), 'pctT' => $act ? $pct . '%' : 'No activity',
                'pc' => $act && $pct < 60 ? 'r' : '', 'lc' => $x['late_days'] > 0 ? 'r' : '',
                'exc' => $x['exc_days'] > 0, 'short' => $act && $x['work'] / max(1, $x['days']) < 6 * 3600,
            ];
        })->sortByDesc('pct')->values()->all(); // highest productivity first
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        $depts = '';
        foreach (array_unique(array_filter(array_column($data, 'dept'), fn ($d) => $d !== '—')) as $d) {
            $depts .= '<option value="' . self::e($d) . '">' . self::e($d) . '</option>';
        }
        $cols = [['n', 'Employee'], ['d', 'Department'], ['days', 'Days'], ['work', 'Working'], ['idle', 'Idle'], ['brk', 'Break'],
            ['away', 'Away'], ['prod', 'Productive'], ['pct', 'Productive %'], ['late', 'Late (min)']];
        $w = [18, 14, 5, 8, 8, 8, 8, 9, 11, 11]; // same column widths for the heading row and every data row
        $th = '';
        foreach ($cols as $ci => [$k, $label]) {
            // first click: names A→Z, numbers high→low (Productive % low→high); clicking again reverses.
            $first = in_array($k, ['n', 'd', 'pct'], true) ? 1 : -1;
            $th .= '<th style="width:' . $w[$ci] . '%"><button class="s" on="tap:AMP.setState({ui: {s: \'' . $k . '\', dir: ui.s == \'' . $k . '\' ? -ui.dir : ' . $first . '}})">'
                . self::e($label) . '<span [text]="ui.s == \'' . $k . '\' ? (ui.dir > 0 ? \' ▲\' : \' ▼\') : \' ⇅\'">' . ($k === 'pct' ? ' ▼' : ' ⇅') . '</span></button></th>';
        }
        $height = 34 + 30 * count($data);
        $t = self::e($title);
        $sb = self::e($sub);
        $n = count($data);

        return <<<AMP
<!doctype html>
<html ⚡4email data-css-strict>
<head>
<meta charset="utf-8">
<script async src="https://cdn.ampproject.org/v0.js"></script>
<script async custom-element="amp-bind" src="https://cdn.ampproject.org/v0/amp-bind-0.1.js"></script>
<script async custom-element="amp-list" src="https://cdn.ampproject.org/v0/amp-list-0.1.js"></script>
<script async custom-template="amp-mustache" src="https://cdn.ampproject.org/v0/amp-mustache-0.2.js"></script>
<style amp4email-boilerplate>body{visibility:hidden}</style>
<style amp-custom>
body{font-family:Segoe UI,Arial,sans-serif;font-size:13px;color:#0F1E26}
.hd{background:#052A33;color:#fff;padding:14px 18px;border-radius:8px 8px 0 0}.hd b{font-size:16px}.hd div{font-size:12px;color:#9FC3CB;margin-top:2px}
.bd{border:1px solid #E5E1D8;border-top:none;padding:14px 18px;border-radius:0 0 8px 8px}
.f{margin:0 0 10px}.f input,.f select{padding:6px 8px;border:1px solid #D5D0C4;border-radius:6px;font-size:13px;margin:0 8px 6px 0}
.f label{margin-right:12px;font-size:12px}
table{border-collapse:collapse;width:100%;font-size:12px;table-layout:fixed}td{overflow:hidden}th{background:#0E7C8F;padding:0;text-align:left}
.s{background:#0E7C8F;color:#fff;border:0;padding:7px 6px;font-weight:600;font-size:12px;cursor:pointer;width:100%;text-align:left;font-family:inherit}
td{padding:6px;border-bottom:1px solid #EDEAE2}.r{color:#D92D20;font-weight:700}.m{color:#8494A0;font-size:11px}
</style>
</head>
<body>
<amp-state id="rows"><script type="application/json">{$json}</script></amp-state>
<amp-state id="ui"><script type="application/json">{"q":"","dept":"","low":false,"late":false,"exc":false,"short":false,"s":"pct","dir":-1}</script></amp-state>
<div class="hd"><b>{$t}</b><div>{$sb}</div></div>
<div class="bd">
<div class="f">
<input type="text" placeholder="Search employee or department" on="input-debounced:AMP.setState({ui: {q: event.value.toLowerCase()}})">
<select on="change:AMP.setState({ui: {dept: event.value}})"><option value="">All departments</option>{$depts}</select>
<label><input type="checkbox" on="change:AMP.setState({ui: {low: event.checked}})"> Productivity below 60%</label>
<label><input type="checkbox" on="change:AMP.setState({ui: {exc: event.checked}})"> Break exceeded</label>
<label><input type="checkbox" on="change:AMP.setState({ui: {short: event.checked}})"> Working under 6 h</label>
<label><input type="checkbox" on="change:AMP.setState({ui: {late: event.checked}})"> Late over 10 min</label>
</div>
<table><tr>{$th}</tr></table>
<amp-list layout="fixed-height" height="{$height}" src="amp-state:rows" items="."
  [src]="rows.filter(r => (ui.q == '' || r.k.indexOf(ui.q) != -1) && (ui.dept == '' || r.dept == ui.dept) && (!ui.low || r.pc == 'r') && (!ui.late || r.lc == 'r') && (!ui.exc || r.exc) && (!ui.short || r.short)).sort((a, b) => a[ui.s] < b[ui.s] ? -ui.dir : (a[ui.s] > b[ui.s] ? ui.dir : 0))">
<template type="amp-mustache">
<table><tr><td style="width:18%"><b>{{name}}</b></td><td style="width:14%">{{dept}}</td><td style="width:5%">{{days}}</td><td style="width:8%">{{workT}}</td><td style="width:8%">{{idleT}}</td><td style="width:8%">{{brkT}}</td><td style="width:8%">{{awayT}}</td><td style="width:9%">{{prodT}}</td><td style="width:11%" class="{{pc}}">{{pctT}}</td><td style="width:11%" class="{{lc}}">{{late}}</td></tr></table>
</template>
</amp-list>
<p class="m">{$n} employees · click a heading to sort (again to reverse) · Red = productivity below 60% / late login over 10 min.</p>
</div>
</body>
</html>
AMP;
    }
}
