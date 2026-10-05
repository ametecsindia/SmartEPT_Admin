<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureEndpointSecurity;
use App\Models\AuditLog;
use App\Models\EmployeeDevice;
use App\Models\EndpointSecurityCommand;
use App\Models\EndpointSecurityEvent;
use App\Models\EndpointSecurityPolicy;
use App\Models\EndpointSecurityStatus;
use App\Models\EndpointSecurityThreat;
use App\Models\EnforcementMachine;
use App\Models\User;
use App\Services\EndpointSecurity\Entitlement;
use App\Services\EndpointSecurity\SecurityCompliance;
use App\Support\CardAccess;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoint Security console API (Oct-2026). Plan gate: EnsureEndpointSecurity on
 * the route group (+ per-capability checks here). Access: the "Endpoint Security"
 * cards in the role matrix (CardAccess). Visibility: the caller's report scope.
 */
class EndpointSecurityController extends Controller
{
    use ScopesVisibleEmployees;

    /** Friendly text for every structured code. Raw endpoint text is never shown. */
    public const ERRORS = [
        'SECURITY_PROVIDER_UNAVAILABLE' => 'The antivirus could not be queried on this computer.',
        'DEFENDER_NOT_INSTALLED' => 'Microsoft Defender is not installed on this computer.',
        'DEFENDER_NOT_ACTIVE' => 'Microsoft Defender is not the active antivirus on this computer.',
        'THIRD_PARTY_AV_ACTIVE' => 'Another antivirus protects this computer. SmartEPT only monitors it; Defender actions are not run.',
        'INSUFFICIENT_PRIVILEGES' => 'The SmartEPT Agent Service does not have the rights needed for this action.',
        'SIGNATURE_UPDATE_FAILED' => 'Microsoft Defender could not update its security intelligence (check internet/proxy access).',
        'SCAN_ALREADY_RUNNING' => 'Another scan or update is already running on this computer.',
        'SCAN_FAILED' => 'Microsoft Defender reported that the scan failed.',
        'INVALID_SCAN_PATH' => 'That folder path is not allowed or does not exist on this computer.',
        'COMMAND_TIMEOUT' => 'The action took too long and was stopped.',
        'COMMAND_EXPIRED' => 'The computer did not pick this up in time (offline or switched off).',
        'DUPLICATE_COMMAND' => 'This action was already received.',
        'UNSUPPORTED_OS' => 'This computer\'s operating system is not supported.',
        'SECURITY_CENTER_UNAVAILABLE' => 'Windows Security Center is not available (normal on Windows Server).',
        'UNKNOWN_COMMAND' => 'The computer did not recognise this action — update the SmartEPT Agent.',
        'INTERNAL_ERROR' => 'An internal error occurred on the computer.',
        'FEATURE_NOT_AVAILABLE' => 'Not included in the current plan.',
        'AGENT_UPGRADE_REQUIRED' => 'Endpoint Security agent upgrade required on this computer.',
    ];

    /** route action => [command type, capability] */
    private const ACTIONS = [
        'refresh' => ['AV_STATUS_REFRESH', 'refresh'],
        'quick-scan' => ['AV_QUICK_SCAN', 'quick_scan'],
        'update-signatures' => ['AV_SIGNATURE_UPDATE', 'signature_update'],
        'full-scan' => ['AV_FULL_SCAN', 'full_scan'],
        'custom-scan' => ['AV_CUSTOM_SCAN', 'custom_scan'],
    ];

    /** What this user may see/do. Never 403s — the console uses it to show or hide the nav. */
    public function access(Request $request): JsonResponse
    {
        $user = $request->user();
        $level = Entitlement::level($user->company);
        $admin = $user->hasRole('SUPER_ADMIN', 'COMPANY_ADMIN');
        $held = $admin ? [] : $user->permissionSlugs();
        $can = fn (string $card, string $lv) => $admin || CardAccess::holds($held, ["endsec.$card"], $lv);

        return response()->json(['data' => [
            'enabled' => (bool) config('endpoint_security.enabled'),
            'level' => $level,
            'capabilities' => Entitlement::capabilities($user->company),
            'can_view' => $level !== Entitlement::NONE && $can('security_overview', 'view'),
            'can_act' => $level !== Entitlement::NONE && $can('security_overview', 'edit'),
            'can_view_policy' => $level !== Entitlement::NONE && $can('security_policy', 'view'),
            'can_edit_policy' => Entitlement::can($user->company, 'policies') && $can('security_policy', 'edit'),
        ]]);
    }

    public function overview(Request $request): JsonResponse
    {
        $rows = $this->rows($request);
        $count = fn (callable $f) => count(array_filter($rows, $f));

        return response()->json([
            'summary' => [
                'total' => count($rows),
                'protected' => $count(fn ($r) => $r['compliance'] === SecurityCompliance::COMPLIANT),
                'attention' => $count(fn ($r) => $r['compliance'] === SecurityCompliance::ACTION_REQUIRED),
                'non_compliant' => $count(fn ($r) => $r['compliance'] === SecurityCompliance::NON_COMPLIANT),
                'unknown' => $count(fn ($r) => $r['compliance'] === SecurityCompliance::UNKNOWN),
                'realtime_disabled' => $count(fn ($r) => $r['realtime'] === false),
                'signatures_outdated' => $count(fn ($r) => in_array('SECURITY_SIGNATURE_OUTDATED', $r['issues'], true)),
                'threats' => $count(fn ($r) => (int) $r['active_threats'] > 0),
                'firewall_issues' => $count(fn ($r) => $r['firewall'] === 'off'),
                'upgrade_required' => $count(fn ($r) => $r['upgrade_required'] && ! $r['service_offline']),
                'service_offline' => $count(fn ($r) => $r['upgrade_required'] && $r['service_offline']),
            ],
            'data' => array_values($rows),
        ]);
    }

    public function show(Request $request, EnforcementMachine $machine): JsonResponse
    {
        $row = $this->rowFor($request, $machine);
        $s = EndpointSecurityStatus::where('enforcement_machine_id', $machine->id)->first();
        $threats = EndpointSecurityThreat::where('enforcement_machine_id', $machine->id);

        return response()->json(['data' => array_merge($row, [
            'os_version' => $row['os_version'],
            'running_mode' => $s?->running_mode,
            'behavior' => $s?->behavior_enabled,
            'ioav' => $s?->ioav_enabled,
            'antivirus_installed' => $s?->antivirus_installed,
            'installed_providers' => $s?->installed_providers ?? [],
            'last_quick_scan_at' => $s?->last_quick_scan_at?->toIso8601String(),
            'last_full_scan_at' => $s?->last_full_scan_at?->toIso8601String(),
            'firewall_profiles' => ['domain' => $s?->firewall_domain, 'private' => $s?->firewall_private, 'public' => $s?->firewall_public],
            'resolved_threats' => (clone $threats)->where('active', false)->count(),
            'issue_labels' => array_map(fn ($c) => SecurityCompliance::LABELS[$c] ?? $c, $row['issues']),
            'errors' => array_map(fn ($c) => self::ERRORS[$c] ?? $c, $s?->errors ?? []),
            'recent_commands' => EndpointSecurityCommand::where('enforcement_machine_id', $machine->id)
                ->latest('id')->limit(10)->get()->map(fn ($c) => $this->commandRow($c))->all(),
        ])]);
    }

    public function threats(Request $request, EnforcementMachine $machine): JsonResponse
    {
        $this->rowFor($request, $machine);

        return response()->json(['data' => EndpointSecurityThreat::where('enforcement_machine_id', $machine->id)
            ->orderByDesc('active')->orderByDesc('detected_at')->limit(500)->get()
            ->map(fn ($t) => $t->only(['threat_id', 'threat_name', 'severity', 'status', 'active', 'action_success', 'resources'])
                + ['detected_at' => $t->detected_at?->toIso8601String(), 'status_changed_at' => $t->status_changed_at?->toIso8601String()])]);
    }

    public function events(Request $request, EnforcementMachine $machine): JsonResponse
    {
        $this->rowFor($request, $machine);

        return response()->json(['data' => EndpointSecurityEvent::where('enforcement_machine_id', $machine->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(300)->get()
            ->map(fn ($e) => ['kind' => $e->kind, 'source' => $e->source, 'event_id' => $e->event_id,
                'detail' => $e->detail ? (SecurityCompliance::LABELS[$e->detail] ?? $e->detail) : null,
                'occurred_at' => $e->occurred_at?->toIso8601String()])]);
    }

    public function action(Request $request, EnforcementMachine $machine, string $action): JsonResponse
    {
        abort_unless(isset(self::ACTIONS[$action]), 404);
        [$type, $capability] = self::ACTIONS[$action];
        if (! Entitlement::can($request->user()->company, $capability)) {
            return EnsureEndpointSecurity::denied($capability);
        }
        $row = $this->rowFor($request, $machine);

        $params = null;
        if ($type === 'AV_CUSTOM_SCAN') {
            $path = self::validPath((string) $request->input('path', ''));
            if ($path === null) {
                return $this->refuse('INVALID_SCAN_PATH', 422);
            }
            $params = ['path' => $path];
        }
        if ($row['upgrade_required']) {
            return $this->refuse('AGENT_UPGRADE_REQUIRED', 409);
        }
        if ($type !== 'AV_STATUS_REFRESH') {
            if ($row['provider_type'] === 'third_party') {
                return $this->refuse('THIRD_PARTY_AV_ACTIVE', 409);
            }
            $busy = EndpointSecurityCommand::where('enforcement_machine_id', $machine->id)
                ->where('type', '!=', 'AV_STATUS_REFRESH')->whereIn('status', EndpointSecurityCommand::OPEN)
                ->where(fn ($q) => $q->where('status', 'running')->orWhere('expires_at', '>', now()))->exists();
            if ($busy) {
                return $this->refuse('SCAN_ALREADY_RUNNING', 409);
            }
        }

        $cmd = EndpointSecurityCommand::create([
            'company_id' => $machine->company_id,
            'enforcement_machine_id' => $machine->id,
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'parameters' => $params,
            'requested_by' => $request->user()->id,
            'requested_ip' => $request->ip(),
            'requested_at' => now(),
            'expires_at' => now()->addMinutes((int) config('endpoint_security.command_ttl_minutes', 60)),
            'status' => 'queued',
        ]);
        $this->audit($request, 'endpoint_security.' . Str::snake(str_replace('-', '_', $action)), 'endpoint_security_command', $cmd->id, [
            'type' => $type, 'parameters' => $params, 'machine' => $machine->hostname,
            'employee' => $row['employee']['name'] ?? null,
        ]);

        return response()->json(['data' => $this->commandRow($cmd)], 201);
    }

    public function commands(Request $request): JsonResponse
    {
        $visible = array_column($this->rows($request), null, 'machine_id');
        $rows = EndpointSecurityCommand::with('requester:id,name')->whereIn('enforcement_machine_id', array_keys($visible) ?: [0])
            ->latest('id')->limit(300)->get()
            ->map(fn ($c) => $this->commandRow($c) + ['device' => $visible[$c->enforcement_machine_id]['device'] ?? null,
                'employee' => $visible[$c->enforcement_machine_id]['employee']['name'] ?? null]);

        return response()->json(['data' => $rows]);
    }

    public function policy(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        return response()->json(['data' => [
            'settings' => $companyId ? EndpointSecurityPolicy::settingsFor($companyId) : config('endpoint_security.policy_defaults'),
            'defaults' => config('endpoint_security.policy_defaults'),
            'editable' => Entitlement::can($request->user()->company, 'policies'),
        ]]);
    }

    public function savePolicy(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        abort_unless($companyId > 0, 422, 'Choose a company first.');
        $data = $request->validate([
            'requireAntivirus' => ['required', 'boolean'],
            'requireRealtimeProtection' => ['required', 'boolean'],
            'maximumSignatureAgeHours' => ['required', 'integer', 'min:1', 'max:720'],
            'requireFirewall' => ['required', 'boolean'],
            'allowThirdPartyAntivirus' => ['required', 'boolean'],
            'pathRedaction' => ['required', 'in:FULL_PATH,REDACT_USER,FILENAME_ONLY,NO_PATH'],
        ]);
        $before = EndpointSecurityPolicy::settingsFor($companyId);
        EndpointSecurityPolicy::updateOrCreate(['company_id' => $companyId], ['settings' => $data, 'updated_by' => $request->user()->id]);

        // Re-evaluate every machine now, so the dashboard reflects the new rules immediately.
        foreach (EndpointSecurityStatus::where('company_id', $companyId)->get() as $s) {
            [$state, $issues, $score] = SecurityCompliance::evaluate($s, $data);
            $s->update(['compliance' => $state, 'compliance_issues' => $issues, 'score' => $score]);
        }
        $this->audit($request, 'endpoint_security.policy_update', 'endpoint_security_policy', $companyId, ['before' => $before, 'after' => $data]);

        return response()->json(['data' => $data]);
    }

    /** CSV reports. Basic: summary, antivirus, signatures, threats, firewall, compliance. Commander adds actions, audit. */
    public function report(Request $request, string $type): StreamedResponse|JsonResponse
    {
        $advanced = ['actions', 'audit'];
        abort_unless(in_array($type, ['summary', 'antivirus', 'signatures', 'threats', 'firewall', 'compliance', ...$advanced], true), 404);
        if (in_array($type, $advanced, true) && ! Entitlement::can($request->user()->company, 'advanced_reports')) {
            return EnsureEndpointSecurity::denied('advanced_reports');
        }
        $rows = $this->rows($request);
        $yn = fn ($v) => $v === null ? 'Unknown' : ($v ? 'Yes' : 'No');

        [$head, $lines] = match ($type) {
            'summary', 'compliance' => [['Employee', 'Device', 'Antivirus', 'Status', 'Issues', 'Last seen'],
                array_map(fn ($r) => [$r['employee']['name'] ?? '', $r['device'], $r['provider'], $r['compliance'],
                    implode('; ', array_map(fn ($c) => SecurityCompliance::LABELS[$c] ?? $c, $r['issues'])), $r['last_seen']], $type === 'compliance'
                    ? array_filter($rows, fn ($r) => $r['compliance'] !== SecurityCompliance::COMPLIANT) : $rows)],
            'antivirus' => [['Employee', 'Device', 'Provider', 'Type', 'Antivirus on', 'Real-time', 'Definitions', 'Last scan'],
                array_map(fn ($r) => [$r['employee']['name'] ?? '', $r['device'], $r['provider'], $r['provider_type'], $yn($r['antivirus_enabled']),
                    $yn($r['realtime']), $r['signature_version'], $r['last_scan']], $rows)],
            'signatures' => [['Employee', 'Device', 'Definitions', 'Updated'],
                array_map(fn ($r) => [$r['employee']['name'] ?? '', $r['device'], $r['signature_version'], $r['signature_updated_at']],
                    array_filter($rows, fn ($r) => in_array('SECURITY_SIGNATURE_OUTDATED', $r['issues'], true)))],
            'firewall' => [['Employee', 'Device', 'Firewall'],
                array_map(fn ($r) => [$r['employee']['name'] ?? '', $r['device'], $r['firewall']], $rows)],
            'threats' => [['Employee', 'Device', 'Threat', 'Status', 'Active', 'Detected'],
                EndpointSecurityThreat::whereIn('enforcement_machine_id', array_column($rows, 'machine_id') ?: [0])->orderByDesc('detected_at')->limit(5000)->get()
                    ->map(fn ($t) => [$this->who($rows, $t->enforcement_machine_id), $this->dev($rows, $t->enforcement_machine_id), $t->threat_name, $t->status,
                        $t->active ? 'Yes' : 'No', $t->detected_at?->toDateTimeString()])->all()],
            'actions' => [['Requested', 'Administrator', 'Device', 'Employee', 'Action', 'Status', 'Received', 'Started', 'Completed', 'Result'],
                EndpointSecurityCommand::with('requester:id,name')->whereIn('enforcement_machine_id', array_column($rows, 'machine_id') ?: [0])
                    ->latest('id')->limit(5000)->get()->map(fn ($c) => [$c->requested_at?->toDateTimeString(), $c->requester?->name,
                        $this->dev($rows, $c->enforcement_machine_id), $this->who($rows, $c->enforcement_machine_id), $c->type, $c->status,
                        $c->received_at?->toDateTimeString(), $c->started_at?->toDateTimeString(), $c->completed_at?->toDateTimeString(), $c->error_message])->all()],
            'audit' => [['When', 'User', 'Action', 'Details', 'IP'],
                AuditLog::with('user:id,name')->where('action', 'like', 'endpoint_security.%')
                    ->when($request->user()->company_id, fn ($q, $c) => $q->where('company_id', $c))->latest('id')->limit(5000)->get()
                    ->map(fn ($a) => [$a->created_at?->toDateTimeString(), $a->user?->name, $a->action, json_encode($a->changes), $a->ip])->all()],
        };
        $this->audit($request, 'endpoint_security.report_export', null, null, ['type' => $type]);

        return response()->streamDownload(function () use ($head, $lines) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $head);
            foreach ($lines as $l) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $l)); // CSV injection guard
            }
            fclose($out);
        }, "endpoint-security-$type-" . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** Windows build => [release, end of security updates for Home/Pro (Microsoft lifecycle)]. */
    // ponytail: hand-kept table; add a row when Microsoft ships a new release.
    public const WINDOWS_SUPPORT = [
        '19045' => ['Windows 10 22H2', '2025-10-14'],
        '22621' => ['Windows 11 22H2', '2024-10-08'],
        '22631' => ['Windows 11 23H2', '2025-11-11'],
        '26100' => ['Windows 11 24H2', '2026-10-13'],
        '26200' => ['Windows 11 25H2', '2027-10-12'],
    ];

    /** Checkpoint key => label, in report order. */
    public const CHECKS = [
        'agent' => 'SmartEPT reporting',
        'antivirus' => 'Antivirus on',
        'realtime' => 'Real-time protection',
        'definitions' => 'Definitions up to date',
        'scan' => 'Scanned in last 7 days',
        'threats' => 'No active threats',
        'firewall' => 'Firewall on',
        'bitlocker' => 'Disk encryption (BitLocker)',
        'os' => 'Windows version supported',
        'patch' => 'Patched in last 45 days',
        'lock' => 'Screen lock ≤ 15 min',
        'macros' => 'Office macros blocked',
        'usb' => 'USB storage blocked',
        'admins' => 'Local administrators',
    ];

    /**
     * 04-Oct-2026: Company Compliance Report — every PC, every checkpoint (current state) plus
     * what happened in the chosen period. The console turns it into the audit-ready PDF.
     */
    public function complianceReport(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $tz = config('app.timezone');
        $from = \Illuminate\Support\Carbon::parse($data['from'], $tz)->startOfDay();
        $to = \Illuminate\Support\Carbon::parse($data['to'], $tz)->endOfDay();
        if ($from->diffInDays($to) > 366) {
            return response()->json(['message' => 'Choose a period of one year or less.'], 422);
        }
        $company = $request->user()->company;
        $rows = $this->rows($request);
        $ids = array_column($rows, 'machine_id') ?: [0];
        $machines = EnforcementMachine::whereIn('id', $ids)->get(['id', 'device_uuid'])->keyBy('id');
        $statuses = EndpointSecurityStatus::whereIn('enforcement_machine_id', $ids)->get()->keyBy('enforcement_machine_id');
        $range = [$from->toDateTimeString(), $to->toDateTimeString()];
        $cid = $company?->id ?? EnforcementMachine::whereIn('id', $ids)->value('company_id');
        $uuids = $machines->pluck('device_uuid')->filter()->values()->all() ?: ['-'];
        $db = fn (string $t) => \Illuminate\Support\Facades\DB::table($t)->where('company_id', $cid);
        $byUuid = fn ($q, string $col = 'device_uuid') => $q->whereIn($col, $uuids)->groupBy($col)
            ->selectRaw("$col as k, count(*) as n")->pluck('n', 'k')->all();

        $threats = EndpointSecurityThreat::whereIn('enforcement_machine_id', $ids)->whereBetween('detected_at', $range)
            ->selectRaw('enforcement_machine_id as k, count(*) as n')->groupBy('enforcement_machine_id')->pluck('n', 'k')->all();
        $scans = EndpointSecurityCommand::whereIn('enforcement_machine_id', $ids)->where('status', 'completed')
            ->whereIn('type', ['AV_QUICK_SCAN', 'AV_FULL_SCAN', 'AV_CUSTOM_SCAN'])->whereBetween('completed_at', $range)
            ->selectRaw('enforcement_machine_id as k, count(*) as n')->groupBy('enforcement_machine_id')->pluck('n', 'k')->all();
        $pa = fn (array $kinds, ?string $outcomeLike = null) => $byUuid($db('pc_audit_events')->whereIn('kind', $kinds)
            ->whereBetween('occurred_at', $range)->when($outcomeLike, fn ($q) => $q->where('outcome', 'like', $outcomeLike)));
        $usb = $pa(['usb_storage']);
        $usbBlocked = $pa(['usb_storage'], 'Blocked%');
        $toUsb = $pa(['file_to_usb']);
        $downloads = $pa(['download']);
        $software = $pa(['software_installed', 'software_removed']);
        $violations = $byUuid($db('employee_compliance_events')->whereBetween('started_at', $range));
        $blockedApps = $byUuid($db('enforcement_audit_events')->where('outcome', 'BLOCKED')->whereBetween('last_seen_at', $range));
        $tamper = $byUuid($db('agent_tamper_events')->whereBetween('occurred_at', $range));
        $usbPolicy = (bool) ($company?->block_removable_storage ?? \App\Models\Company::whereKey($cid)->value('block_removable_storage'));

        $out = [];
        foreach ($rows as $r) {
            $s = $statuses[$r['machine_id']] ?? null;
            $u = $machines[$r['machine_id']]->device_uuid ?? '-';
            $n = fn (array $a, $k) => (int) ($a[$k] ?? 0);
            $period = [
                'threats' => $n($threats, $r['machine_id']), 'scans' => $n($scans, $r['machine_id']),
                'usb' => $n($usb, $u), 'usb_blocked' => $n($usbBlocked, $u), 'files_to_usb' => $n($toUsb, $u),
                'downloads' => $n($downloads, $u), 'software' => $n($software, $u), 'violations' => $n($violations, $u),
                'blocked_apps' => $n($blockedApps, $u), 'tamper' => $n($tamper, $u),
            ];
            $checks = self::checkpoints($r, $s, $usbPolicy);
            $out[] = [
                'device' => $r['device'], 'employee' => $r['employee']['name'] ?? null, 'employee_code' => $r['employee']['code'] ?? null,
                'os' => trim(($s?->posture['osName'] ?? $r['os_version'] ?? '') . ' ' . ($s?->posture['osRelease'] ?? '')),
                'compliance' => $r['compliance'], 'score' => $r['score'], 'last_seen' => $r['last_seen'],
                'checks' => $checks, 'period' => $period,
            ];
        }

        $sum = fn (string $k) => array_sum(array_map(fn ($o) => $o['period'][$k], $out));
        $pass = [];
        foreach (array_keys(self::CHECKS) as $k) {
            $pass[$k] = ['pass' => 0, 'fail' => 0, 'unknown' => 0];
            foreach ($out as $o) {
                $st = $o['checks'][$k]['s'];
                $pass[$k][$st === 'pass' ? 'pass' : ($st === 'fail' ? 'fail' : 'unknown')]++;
            }
        }
        $this->audit($request, 'endpoint_security.report_export', null, null, ['type' => 'compliance_pdf', 'from' => $data['from'], 'to' => $data['to']]);

        return response()->json(['data' => [
            'company' => $company?->name ?? \App\Models\Company::whereKey($cid)->value('name'),
            'from' => $data['from'], 'to' => $data['to'], 'generated_at' => now()->toIso8601String(),
            'checks' => self::CHECKS, 'check_totals' => $pass,
            'totals' => [
                'pcs' => count($out),
                'fully_compliant' => count(array_filter($out, fn ($o) => ! array_filter($o['checks'], fn ($c) => $c['s'] === 'fail'))),
                'with_failures' => count(array_filter($out, fn ($o) => array_filter($o['checks'], fn ($c) => $c['s'] === 'fail'))),
                'threats' => $sum('threats'), 'scans' => $sum('scans'), 'usb' => $sum('usb'), 'usb_blocked' => $sum('usb_blocked'),
                'files_to_usb' => $sum('files_to_usb'), 'downloads' => $sum('downloads'), 'software' => $sum('software'),
                'violations' => $sum('violations'), 'blocked_apps' => $sum('blocked_apps'), 'tamper' => $sum('tamper'),
            ],
            'pcs' => $out,
        ]]);
    }

    /** One PC's checkpoints: s = pass | fail | warn | unknown, t = short text for the table. */
    public static function checkpoints(array $r, ?EndpointSecurityStatus $s, bool $usbPolicy): array
    {
        $p = (array) ($s?->posture ?? []);
        $yn = fn ($v, string $on = 'On', string $off = 'Off') => $v === null ? ['s' => 'unknown', 't' => 'Not reported'] : ($v ? ['s' => 'pass', 't' => $on] : ['s' => 'fail', 't' => $off]);
        $date = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d-M-Y') : null;
        $c = [];

        $seen = $r['last_seen'] ? \Illuminate\Support\Carbon::parse($r['last_seen']) : null;
        $c['agent'] = ! $seen ? ['s' => 'fail', 't' => 'Never reported'] : ($seen->gt(now()->subDay()) ? ['s' => 'pass', 't' => $date($r['last_seen'])] : ['s' => 'fail', 't' => 'Silent since ' . $date($r['last_seen'])]);
        $c['antivirus'] = $r['provider'] ? $yn($r['antivirus_enabled'], $r['provider'], $r['provider'] . ' off') : ['s' => 'unknown', 't' => 'Not reported'];
        $c['realtime'] = $yn($r['realtime']);
        $outdated = in_array('SECURITY_SIGNATURE_OUTDATED', $r['issues'], true);
        $c['definitions'] = ! $s ? ['s' => 'unknown', 't' => 'Not reported'] : ['s' => $outdated ? 'fail' : 'pass', 't' => $date($r['signature_updated_at']) ?? ($outdated ? 'Outdated' : 'Current')];
        $c['scan'] = ! $r['last_scan'] ? ['s' => $s ? 'fail' : 'unknown', 't' => $s ? 'No scan recorded' : 'Not reported']
            : ['s' => \Illuminate\Support\Carbon::parse($r['last_scan'])->gt(now()->subDays(7)) ? 'pass' : 'fail', 't' => $date($r['last_scan'])];
        $c['threats'] = $r['active_threats'] === null ? ['s' => 'unknown', 't' => 'Not reported'] : ((int) $r['active_threats'] > 0 ? ['s' => 'fail', 't' => $r['active_threats'] . ' active'] : ['s' => 'pass', 't' => 'None']);
        $c['firewall'] = ['on' => ['s' => 'pass', 't' => 'On'], 'off' => ['s' => 'fail', 't' => 'Off (a profile)']][$r['firewall']] ?? ['s' => 'unknown', 't' => 'Not reported'];
        $bl = $p['bitlocker'] ?? null;
        $c['bitlocker'] = $bl === null ? ['s' => 'unknown', 't' => 'Not reported'] : (strcasecmp($bl, 'On') === 0 ? ['s' => 'pass', 't' => 'On'] : ['s' => 'fail', 't' => 'Off']);

        $build = $p['osBuild'] ?? null;
        $sup = $build ? (self::WINDOWS_SUPPORT[$build] ?? null) : null;
        if (! $build) {
            $c['os'] = ['s' => 'unknown', 't' => 'Not reported'];
        } elseif ($sup) {
            $end = \Illuminate\Support\Carbon::parse($sup[1]);
            $c['os'] = ['s' => $end->isPast() ? 'fail' : ($end->lt(now()->addDays(60)) ? 'warn' : 'pass'),
                't' => $sup[0] . ($end->isPast() ? ' — support ended ' : ' — until ') . $end->format('d-M-Y')];
        } else {
            $c['os'] = ['s' => (int) $build < 19045 ? 'fail' : 'unknown', 't' => 'Build ' . $build];
        }
        $lp = $p['lastPatch'] ?? null;
        $c['patch'] = ! $lp ? ['s' => 'unknown', 't' => 'Not reported']
            : ['s' => \Illuminate\Support\Carbon::parse($lp)->gt(now()->subDays(45)) ? 'pass' : 'fail', 't' => $date($lp) . (isset($p['lastPatchId']) ? ' (' . $p['lastPatchId'] . ')' : '')];
        $lock = $p['lockSecs'] ?? null;
        $c['lock'] = $lock === null ? ['s' => $s?->posture ? 'fail' : 'unknown', 't' => $s?->posture ? 'Not enforced' : 'Not reported']
            : ((int) $lock > 0 && (int) $lock <= 900 ? ['s' => 'pass', 't' => round($lock / 60) . ' min'] : ['s' => 'fail', 't' => (int) $lock ? round($lock / 60) . ' min' : 'Not enforced']);
        $mb = $p['macrosBlocked'] ?? null;
        $c['macros'] = $mb === null ? ['s' => 'unknown', 't' => $s?->posture ? 'No Office policy' : 'Not reported'] : (strcasecmp($mb, 'True') === 0 ? ['s' => 'pass', 't' => 'Blocked'] : ['s' => 'fail', 't' => 'Allowed']);
        $c['usb'] = $usbPolicy ? ['s' => 'pass', 't' => 'Blocked by SmartEPT'] : ['s' => 'fail', 't' => 'Allowed'];
        $admins = $p['admins'] ?? null;
        $c['admins'] = $admins === null ? ['s' => 'unknown', 't' => 'Not reported']
            : ['s' => count($admins) > 2 ? 'warn' : 'pass', 't' => count($admins) . ': ' . implode(', ', array_map(fn ($a) => \Illuminate\Support\Str::afterLast($a, '\\'), $admins))];

        return $c;
    }

    // ---------------------------------------------------------------- helpers

    /** Server mirror of the endpoint's ValidateScanPath (the endpoint's check is authoritative). */
    public static function validPath(string $in): ?string
    {
        $p = str_replace('/', '\\', trim($in));
        if (strlen($p) < 3 || strlen($p) > 260 || str_starts_with($p, '\\\\') || ! preg_match('/^[A-Za-z]:\\\\[A-Za-z0-9 _.\-()\\\\]*$/', $p)) {
            return null;
        }
        $parts = [];
        foreach (explode('\\', substr($p, 3)) as $s) {
            if ($s === '' || $s === '.') {
                continue;
            }
            if ($s === '..' || trim($s, '. ') === '') {
                return null;
            }
            $parts[] = $s;
        }

        return strtoupper($p[0]) . ':\\' . implode('\\', $parts);
    }

    private function refuse(string $code, int $http): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => self::ERRORS[$code] ?? $code]], $http);
    }

    private function who(array $rows, int $id): ?string
    {
        foreach ($rows as $r) {
            if ($r['machine_id'] === $id) {
                return $r['employee']['name'] ?? null;
            }
        }

        return null;
    }

    private function dev(array $rows, int $id): ?string
    {
        foreach ($rows as $r) {
            if ($r['machine_id'] === $id) {
                return $r['device'];
            }
        }

        return null;
    }

    /** 404 when the machine is outside the caller's company (scope) or report visibility. */
    private function rowFor(Request $request, EnforcementMachine $machine): array
    {
        $rows = $this->rows($request, $machine->id);
        abort_unless($rows, 404);

        return $rows[0];
    }

    /** One row per active machine the caller may see. */
    private function rows(Request $request, ?int $onlyMachine = null): array
    {
        $visible = $this->visibleEmployeeIds($request->user());
        $machines = EnforcementMachine::whereNull('revoked_at')
            ->when($onlyMachine, fn ($q) => $q->whereKey($onlyMachine))->orderBy('hostname')->get();
        $statuses = EndpointSecurityStatus::whereIn('enforcement_machine_id', $machines->pluck('id'))->get()->keyBy('enforcement_machine_id');
        $empIds = $machines->pluck('signed_in_employee_id')->filter()->unique();
        // Current device rows, newest heartbeat last (keyBy keeps the last = the live one).
        $allDevices = EmployeeDevice::with('employee')
            ->where(fn ($q) => $q->whereIn('device_uuid', $machines->pluck('device_uuid')->filter())->orWhereIn('employee_id', $empIds))
            ->get()->sortBy(fn ($d) => $d->last_heartbeat_at?->getTimestamp() ?? 0);
        $devices = $allDevices->keyBy('device_uuid');
        // A phone is never a PC's name.
        $latestByEmployee = $allDevices->filter(fn ($d) => stripos((string) $d->os_version, 'android') === false)->keyBy('employee_id');
        $employees = \App\Models\Employee::whereIn('id', $empIds)->get()->keyBy('id');
        $open = EndpointSecurityCommand::whereIn('enforcement_machine_id', $machines->pluck('id'))
            ->whereIn('status', EndpointSecurityCommand::OPEN)->where('type', '!=', 'AV_STATUS_REFRESH')
            ->latest('id')->get()->keyBy('enforcement_machine_id');

        $out = [];
        foreach ($machines as $m) {
            // 03-Oct-2026: never show stale names. enforcement_machines.hostname and device_uuid are
            // fixed at enrolment; a PC renamed or reinstalled since (new agent device_uuid) kept its
            // old name here while Devices showed the new one. Order of trust:
            //   1. the hostname the service itself reports with every security sync (always current);
            //   2. the agent device row the signed-in employee is using right now (newest heartbeat);
            //   3. the device row the machine enrolled with; 4. the enrolment hostname.
            $dev = $m->device_uuid ? ($devices[$m->device_uuid] ?? null) : null;
            $live = $m->signed_in_employee_id ? ($latestByEmployee[$m->signed_in_employee_id] ?? null) : null;
            // A record not heard from for a day borrows it ONLY when its own device row is gone (PC
            // renamed/reinstalled, e.g. KN8IQRK -> AH). If its own device still exists it is a
            // different PC (e.g. LRCR8LO) and keeps its own name.
            $alive = $m->last_seen_at && $m->last_seen_at->gt(now()->subDay());
            if ($live && (! $dev || $alive && ($live->last_heartbeat_at?->getTimestamp() ?? 0) > ($dev->last_heartbeat_at?->getTimestamp() ?? 0))) {
                $dev = $live;
            }
            $emp = ($m->signed_in_employee_id ? ($employees[$m->signed_in_employee_id] ?? null) : null) ?? $dev?->employee;
            if ($visible !== null && (! $emp || ! in_array($emp->id, $visible, true))) {
                continue;
            }
            $s = $statuses[$m->id] ?? null;
            $fw = $s ? [$s->firewall_domain, $s->firewall_private, $s->firewall_public] : [null];
            $scans = array_filter([$s?->last_quick_scan_at, $s?->last_full_scan_at]);
            $cmd = $open[$m->id] ?? null;
            $out[] = [
                'machine_id' => $m->id,
                'device' => ($statuses[$m->id] ?? null)?->hostname ?: ($dev?->computer_name ?: ($m->hostname ?: ('PC #' . $m->id))),
                'os_version' => $dev?->os_version ?: $m->os_version,
                'employee' => $emp ? ['id' => $emp->id, 'name' => $emp->fullName(), 'code' => $emp->employee_code] : null,
                'provider' => $s?->provider,
                'provider_type' => $s?->provider_type ?? 'unknown',
                'monitoring_only' => ($s?->provider_type) === 'third_party',
                'antivirus_enabled' => $s?->antivirus_enabled,
                'realtime' => $s?->realtime_enabled,
                'signature_version' => $s?->signature_version,
                'signature_updated_at' => $s?->signature_updated_at?->toIso8601String(),
                'last_scan' => $scans ? max($scans)->toIso8601String() : null,
                'active_threats' => $s?->active_threat_count,
                'firewall' => in_array(false, $fw, true) ? 'off' : (in_array(null, $fw, true) ? 'unknown' : 'on'),
                'compliance' => $s ? SecurityCompliance::displayState($s) : SecurityCompliance::UNKNOWN,
                'issues' => $s?->compliance_issues ?? [],
                'score' => $s?->score,
                'last_seen' => $s?->received_at?->toIso8601String(),
                // 04-Oct-2026: a PC enrolled minutes ago has simply not reported yet - calling it
                // "Agent upgrade required" made a freshly installed 0.29 read as not detected.
                'waiting' => ! $s && $m->enrolled_at && $m->enrolled_at->gt(now()->subMinutes(15)),
                'upgrade_required' => $s ? $s->capability !== 'endpoint_security_v1' : ! ($m->enrolled_at && $m->enrolled_at->gt(now()->subMinutes(15))),
                // What the SmartEPT Agent Service last said in its enforcement heartbeat —
                // tells "old agent" apart from "new agent that has not reported yet".
                'service_version' => $m->enforcer_version,
                'service_last_seen' => $m->last_seen_at?->toIso8601String(),
                'service_offline' => ! $alive,
                'pending' => $cmd ? ['type' => $cmd->type, 'status' => $cmd->status] : null,
            ];
        }

        return $out;
    }

    /**
     * 04-Oct-2026: the result of one scan / signature update - what ran, how long it took,
     * what Microsoft Defender found and did during it. Defender's own threat records and
     * events inside the command's time window are the evidence; nothing is inferred.
     */
    public function commandReport(Request $request, EndpointSecurityCommand $command): JsonResponse
    {
        $machine = EnforcementMachine::findOrFail($command->enforcement_machine_id);
        $row = $this->rowFor($request, $machine); // 404 outside the caller's scope
        $from = ($command->started_at ?? $command->received_at ?? $command->requested_at)?->copy()->subMinute();
        $to = ($command->completed_at ?? now())->copy()->addMinutes(2); // Defender writes its records just after
        $threats = EndpointSecurityThreat::where('enforcement_machine_id', $machine->id)
            ->where(fn ($q) => $q->whereBetween('detected_at', [$from, $to])->orWhereBetween('status_changed_at', [$from, $to]))
            ->orderBy('detected_at')->get();
        $events = EndpointSecurityEvent::where('enforcement_machine_id', $machine->id)->where('source', 'defender')
            ->whereBetween('occurred_at', [$from, $to])->orderBy('occurred_at')->get();
        $s = EndpointSecurityStatus::where('enforcement_machine_id', $machine->id)->first();
        $secs = $command->started_at && $command->completed_at ? $command->started_at->diffInSeconds($command->completed_at) : null;

        return response()->json(['data' => $this->commandRow($command) + [
            'machine_id' => $machine->id,
            'device' => $row['device'],
            'employee' => $row['employee']['name'] ?? null,
            'duration_seconds' => $secs === null ? null : (int) $secs,
            'outcome' => match (true) {
                in_array($command->status, EndpointSecurityCommand::OPEN, true) => 'Still running on the PC — open this report again when it completes.',
                $command->status === 'completed' && $threats->isEmpty() => 'Completed — no threats found.',
                $command->status === 'completed' => 'Completed — ' . $threats->count() . ' threat(s) found by Microsoft Defender.',
                default => $command->error_message ?: 'The action did not complete.',
            },
            'threats' => $threats->map(fn ($t) => ['name' => $t->threat_name ?: $t->threat_id, 'severity' => $t->severity, 'status' => $t->status,
                'active' => $t->active, 'action_success' => $t->action_success, 'resources' => $t->resources ?? [],
                'detected_at' => $t->detected_at?->toIso8601String()])->values(),
            'events' => $events->map(fn ($e) => ['kind' => $e->kind, 'event_id' => $e->event_id,
                'occurred_at' => $e->occurred_at?->toIso8601String()])->values(),
            'after' => ['last_quick_scan_at' => $s?->last_quick_scan_at?->toIso8601String(), 'last_full_scan_at' => $s?->last_full_scan_at?->toIso8601String(),
                'signature_version' => $s?->signature_version, 'signature_updated_at' => $s?->signature_updated_at?->toIso8601String(),
                'active_threats' => $s?->active_threat_count],
        ]]);
    }

    private function commandRow(EndpointSecurityCommand $c): array
    {
        return [
            'id' => $c->id, 'type' => $c->type, 'status' => $c->status, 'parameters' => $c->parameters,
            'requested_by' => $c->requester?->name, 'requested_at' => $c->requested_at?->toIso8601String(),
            'received_at' => $c->received_at?->toIso8601String(), 'started_at' => $c->started_at?->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),
            'error_code' => $c->error_code, 'error_message' => $c->error_message,
        ];
    }
}
