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
                'upgrade_required' => $count(fn ($r) => $r['upgrade_required']),
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
            'os_version' => $machine->os_version,
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
        $devices = EmployeeDevice::with('employee')->whereIn('device_uuid', $machines->pluck('device_uuid')->filter())
            ->get()->sortBy(fn ($d) => $d->last_heartbeat_at?->getTimestamp() ?? 0)->keyBy('device_uuid'); // newest wins
        $empIds = $machines->pluck('signed_in_employee_id')->filter()->unique();
        $employees = \App\Models\Employee::whereIn('id', $empIds)->get()->keyBy('id');
        $open = EndpointSecurityCommand::whereIn('enforcement_machine_id', $machines->pluck('id'))
            ->whereIn('status', EndpointSecurityCommand::OPEN)->where('type', '!=', 'AV_STATUS_REFRESH')
            ->latest('id')->get()->keyBy('enforcement_machine_id');

        $out = [];
        foreach ($machines as $m) {
            $dev = $m->device_uuid ? ($devices[$m->device_uuid] ?? null) : null;
            $emp = $dev?->employee ?? ($m->signed_in_employee_id ? ($employees[$m->signed_in_employee_id] ?? null) : null);
            if ($visible !== null && (! $emp || ! in_array($emp->id, $visible, true))) {
                continue;
            }
            $s = $statuses[$m->id] ?? null;
            $fw = $s ? [$s->firewall_domain, $s->firewall_private, $s->firewall_public] : [null];
            $scans = array_filter([$s?->last_quick_scan_at, $s?->last_full_scan_at]);
            $cmd = $open[$m->id] ?? null;
            $out[] = [
                'machine_id' => $m->id,
                'device' => $dev?->computer_name ?: ($m->hostname ?: ('PC #' . $m->id)),
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
                'upgrade_required' => ! $s || $s->capability !== 'endpoint_security_v1',
                'pending' => $cmd ? ['type' => $cmd->type, 'status' => $cmd->status] : null,
            ];
        }

        return $out;
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
