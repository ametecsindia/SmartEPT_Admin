<?php

namespace App\Services\PcAudit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 04-Oct-2026 — PC Audit Log: everything that happened on ONE PC in a date range, as one
 * time-ordered list.
 *
 * Read-only over the tables that already hold the data (sessions, apps, websites, idle,
 * violations, enforcement blocks, tamper attempts, Defender threats/events) plus
 * pc_audit_events, the one new table (clicks from the agent; software, USB/devices,
 * downloads, files copied to USB and network shares from the SmartEPT Agent Service).
 *
 * DB::table with an explicit company_id everywhere, so it gives the same answer inside a
 * request and in the background report run.
 */
class PcAuditLog
{
    public const CATEGORIES = [
        'session' => 'Sign-in / sign-out',
        'app' => 'Application',
        'web' => 'Website',
        'click' => 'Click',
        'idle' => 'Idle',
        'software' => 'Software change',
        'device' => 'External device',
        'download' => 'Download',
        'file_share' => 'File sharing',
        'blocked' => 'Blocked / violation',
        'security' => 'Security (Defender)',
        'tamper' => 'Agent tamper',
    ];

    /** pc_audit_events.kind => category */
    public const KIND_CATEGORY = [
        'click' => 'click',
        'software_installed' => 'software', 'software_removed' => 'software', 'software_updated' => 'software',
        'windows_update' => 'software',
        'usb_storage' => 'device', 'device_connected' => 'device',
        'download' => 'download',
        'file_to_usb' => 'file_share', 'share_connected' => 'file_share',
    ];

    /**
     * @return array<int, array{at:string, category:string, event:string, detail:string, outcome:string, employee_id:?int}>
     *         ascending by time
     */
    public static function forDevice(int $companyId, string $uuid, Carbon $from, Carbon $to, ?array $only = null): array
    {
        $want = fn (string $c) => $only === null || in_array($c, $only, true);
        $range = [$from->toDateTimeString(), $to->toDateTimeString()];
        $t = fn (string $table) => DB::table($table)->where('company_id', $companyId)->where('device_uuid', $uuid);
        $rows = [];
        $add = function ($at, string $cat, string $event, string $detail = '', string $outcome = '', $emp = null) use (&$rows) {
            if ($at) {
                $rows[] = ['at' => (string) $at, 'category' => $cat, 'event' => $event, 'detail' => trim($detail, " ·"),
                    'outcome' => $outcome, 'employee_id' => $emp ? (int) $emp : null];
            }
        };

        if ($want('session')) {
            foreach ($t('employee_login_sessions')->where(fn ($q) => $q->whereBetween('login_at', $range)->orWhereBetween('logout_at', $range))->get() as $r) {
                if ($r->login_at >= $range[0] && $r->login_at <= $range[1]) {
                    $add($r->login_at, 'session', 'Signed in to SmartEPT', $r->login_ip ? 'IP ' . $r->login_ip : '', '', $r->employee_id);
                }
                if ($r->logout_at && $r->logout_at >= $range[0] && $r->logout_at <= $range[1]) {
                    $add($r->logout_at, 'session', 'Signed out', $r->logout_reason ? 'Reason: ' . strtolower($r->logout_reason) : '', '', $r->employee_id);
                }
            }
        }
        if ($want('app')) {
            foreach ($t('employee_app_usage_logs')->whereBetween('start_at', $range)->orderBy('start_at')->get() as $r) {
                $add($r->start_at, 'app', 'Used ' . ($r->app_name ?: $r->process_name ?: 'an application'),
                    trim(($r->process_name ? $r->process_name . ' · ' : '') . ($r->window_title ?? '')) . ' · ' . self::dur($r->duration_seconds),
                    self::usageOutcome($r->category, $r->compliance_status), $r->employee_id);
            }
        }
        if ($want('web')) {
            foreach ($t('employee_website_usage_logs')->whereBetween('start_at', $range)->orderBy('start_at')->get() as $r) {
                $add($r->start_at, 'web', 'Visited ' . ($r->domain ?: 'a website'),
                    trim(($r->full_url ?: '') . ($r->page_title ? ' · ' . $r->page_title : '') . ($r->browser ? ' · ' . $r->browser : '')) . ' · ' . self::dur($r->duration_seconds),
                    self::usageOutcome($r->category, $r->compliance_status), $r->employee_id);
            }
        }
        if ($want('idle')) {
            foreach ($t('employee_activity_events')->where('event_type', 'IDLE')->whereBetween('started_at', $range)->get() as $r) {
                $add($r->started_at, 'idle', 'Idle (no keyboard / mouse)', self::dur($r->duration_seconds), '', $r->employee_id);
            }
        }
        if ($only === null || array_intersect($only, array_unique(self::KIND_CATEGORY))) {
            foreach ($t('pc_audit_events')->whereBetween('occurred_at', $range)->get() as $r) {
                $cat = self::KIND_CATEGORY[$r->kind] ?? 'device';
                if ($want($cat)) {
                    [$event, $detail] = self::describe($r->kind, (string) $r->title, (array) json_decode((string) $r->detail, true));
                    if ($cat === 'device' && str_starts_with((string) $r->outcome, 'Blocked')) {
                        // 05-Oct-2026: say plainly that someone TRIED to connect it and it was stopped.
                        $event = '⚠ Blocked — employee tried to connect: ' . preg_replace('/^(USB storage connected|Device connected): /', '', $event);
                    }
                    $add($r->occurred_at, $cat, $event, $detail, (string) $r->outcome, $r->employee_id);
                }
            }
        }
        if ($want('blocked')) {
            foreach ($t('employee_compliance_events')->whereBetween('started_at', $range)->get() as $r) {
                $add($r->started_at, 'blocked', self::human($r->event_type),
                    trim(($r->description ?: '') . ($r->detected_value ? ' · ' . $r->detected_value : '')),
                    $r->action_taken ? self::human($r->action_taken) : 'Recorded', $r->employee_id);
            }
            foreach ($t('enforcement_audit_events')->whereBetween('last_seen_at', $range)->get() as $r) {
                $add($r->last_seen_at, 'blocked', (['BLOCKED' => 'Program blocked: ', 'ALLOWED_BY_RULE' => 'Program allowed by rule: '][$r->outcome] ?? 'Program would be blocked: ') . basename(str_replace('\\', '/', $r->target)),
                    $r->target . ' · ' . $r->occurrences . ' time(s) since ' . $r->first_seen_at . ($r->rule_name ? ' · rule ' . $r->rule_name : ''),
                    ['BLOCKED' => 'Blocked by SmartEPT (' . $r->source . ')', 'ALLOWED_BY_RULE' => 'Allowed'][$r->outcome] ?? 'Audit only', $r->employee_id);
            }
        }
        if ($want('tamper')) {
            foreach ($t('agent_tamper_events')->whereBetween('occurred_at', $range)->get() as $r) {
                $add($r->occurred_at, 'tamper', self::human($r->event_type), (string) $r->reason, self::human($r->outcome), $r->employee_id);
            }
        }
        if ($want('security')) {
            $machines = DB::table('enforcement_machines')->where('company_id', $companyId)->where('device_uuid', $uuid)->pluck('id')->all();
            if ($machines) {
                foreach (DB::table('endpoint_security_threats')->whereIn('enforcement_machine_id', $machines)->whereBetween('detected_at', $range)->get() as $r) {
                    $add($r->detected_at, 'security', 'Threat detected: ' . ($r->threat_name ?: $r->threat_id),
                        implode(', ', (array) json_decode((string) $r->resources, true)),
                        'Microsoft Defender: ' . $r->status . ($r->action_success === null ? '' : ($r->action_success ? ' (action succeeded)' : ' (action failed)')));
                }
                foreach (DB::table('endpoint_security_events')->whereIn('enforcement_machine_id', $machines)->whereBetween('occurred_at', $range)->get() as $r) {
                    $add($r->occurred_at, 'security', self::human($r->kind), (string) $r->detail, $r->source === 'server' ? 'SmartEPT alert' : 'Microsoft Defender');
                }
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $rows;
    }

    /** [event, detail] for a pc_audit_events row. */
    private static function describe(string $kind, string $title, array $d): array
    {
        $s = fn ($k) => isset($d[$k]) && $d[$k] !== '' ? (string) $d[$k] : '';
        return match ($kind) {
            'click' => ['Clicked ' . ($title !== '' ? '"' . $title . '"' : 'in ' . ($s('app') ?: 'a window')),
                trim(($s('control') ? $s('control') . ' · ' : '') . $s('app') . ($s('window') ? ' · ' . $s('window') : '') . ($s('button') === 'right' ? ' · right click' : ''))],
            'software_installed' => ['Installed ' . $title, trim($s('version') . ' ' . ($s('publisher') ? '· ' . $s('publisher') : ''))],
            'software_removed' => ['Uninstalled ' . $title, trim($s('version') . ' ' . ($s('publisher') ? '· ' . $s('publisher') : ''))],
            'software_updated' => ['Updated ' . $title, $s('from') . ' → ' . $s('version')],
            'windows_update' => ['Windows update installed ' . $title, $s('description')],
            'usb_storage' => ['USB storage connected: ' . $title, trim(($s('serial') ? 'Serial ' . $s('serial') : '') . ($s('capacity') ? ' · ' . $s('capacity') : ''))],
            'device_connected' => ['Device connected: ' . $title, trim(($s('class') ? $s('class') . ' · ' : '') . $s('id'))],
            'download' => ['Downloaded ' . $title, trim(($s('from') ? 'from ' . $s('from') : 'source not recorded') . ($s('referrer') ? ' · page ' . $s('referrer') : '') . ($s('size') ? ' · ' . $s('size') : '') . ($s('user') ? ' · user ' . $s('user') : ''))],
            'file_to_usb' => ['File copied to USB drive: ' . $title, trim($s('drive') . ($s('size') ? ' · ' . $s('size') : ''))],
            'share_connected' => ['Connected to network share ' . $title, $s('user') ? 'as ' . $s('user') : ''],
            default => [self::human($kind) . ($title !== '' ? ': ' . $title : ''), ''],
        };
    }

    private static function usageOutcome(?string $category, ?string $compliance): string
    {
        if (in_array($category, ['BLOCKED', 'RESTRICTED'], true)) {
            return ucfirst(strtolower($category)) . ($compliance === 'VIOLATION' ? ' · violation' : '');
        }

        return $compliance === 'VIOLATION' ? 'Violation' : '';
    }

    public static function dur($seconds): string
    {
        $s = (int) $seconds;

        return $s >= 3600 ? intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm' : ($s >= 60 ? intdiv($s, 60) . 'm ' . ($s % 60) . 's' : $s . 's');
    }

    public static function human(?string $code): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', (string) $code)));
    }
}
