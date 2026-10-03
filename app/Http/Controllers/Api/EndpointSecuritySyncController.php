<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EndpointSecurityCommand;
use App\Models\EndpointSecurityEvent;
use App\Models\EndpointSecurityPolicy;
use App\Models\EndpointSecurityStatus;
use App\Models\EndpointSecurityThreat;
use App\Models\EnforcementMachine;
use App\Services\EndpointSecurity\Entitlement;
use App\Services\EndpointSecurity\SecurityCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/enforcer/security/sync — the ONE Endpoint Security call the
 * SmartEPT Agent Service makes (Oct-2026).
 *
 * In:  capabilities, version, health?, threats? (full list when present), events?, results?
 * Out: enabled, server_time, redaction, intervals, commands[] (enum type + validated path only)
 *
 * Separate from /enforcer/heartbeat on purpose: nothing here can change what the
 * enforcement heartbeat answers. A plan without Endpoint Security gets
 * enabled:false and the endpoint idles; nothing is stored.
 */
class EndpointSecuritySyncController extends Controller
{
    /** command type => capability the plan must include */
    public const TYPE_CAPABILITY = [
        'AV_STATUS_REFRESH' => 'refresh',
        'AV_QUICK_SCAN' => 'quick_scan',
        'AV_SIGNATURE_UPDATE' => 'signature_update',
        'AV_FULL_SCAN' => 'full_scan',
        'AV_CUSTOM_SCAN' => 'custom_scan',
    ];

    public function sync(Request $request): JsonResponse
    {
        $machine = $request->user();
        abort_unless($machine instanceof EnforcementMachine && $machine->tokenCan('enforcer'), 403, 'Enforcer credential required.');
        abort_unless($machine->isActive(), 403, 'This endpoint has been revoked.');

        $company = Company::find($machine->company_id);
        if (Entitlement::level($company) === Entitlement::NONE) {
            return response()->json(['ok' => true, 'enabled' => false, 'server_time' => now()->toIso8601String()]);
        }

        $data = $request->validate([
            'capabilities' => ['nullable', 'array', 'max:20'],
            'capabilities.*' => ['string', 'max:40'],
            'version' => ['nullable', 'string', 'max:32'],
            'health' => ['nullable', 'array'],
            'threats' => ['nullable', 'array', 'max:500'],
            'events' => ['nullable', 'array', 'max:500'],
            'results' => ['nullable', 'array', 'max:100'],
        ]);
        $policy = EndpointSecurityPolicy::settingsFor((int) $machine->company_id);

        DB::transaction(function () use ($machine, $data, $policy, $request) {
            $status = EndpointSecurityStatus::withoutGlobalScopes()->firstOrNew(['enforcement_machine_id' => $machine->id]);
            $status->company_id = $machine->company_id;
            $status->device_uuid = $machine->device_uuid;
            $status->capability = in_array('endpoint_security_v1', (array) ($data['capabilities'] ?? []), true) ? 'endpoint_security_v1' : $status->capability;
            $status->agent_version = $data['version'] ?? $status->agent_version;
            $status->received_at = now();

            $oneShot = [];
            $previousProvider = $status->provider;
            $evaluate = false;

            if (! empty($data['health'])) {
                $this->applyHealth($status, $data['health']);
                if ($previousProvider && $status->provider && $previousProvider !== $status->provider) {
                    $oneShot[] = 'SECURITY_PROVIDER_CHANGED';
                }
                $evaluate = true;
            }
            if ($request->has('threats') && is_array($data['threats'] ?? null)) {
                if ($this->applyThreats($machine, $data['threats'])) {
                    $oneShot[] = 'SECURITY_THREAT_DETECTED';
                }
                $q = EndpointSecurityThreat::withoutGlobalScopes()->where('enforcement_machine_id', $machine->id);
                $status->threat_count = (clone $q)->count();
                $status->active_threat_count = (clone $q)->where('active', true)->count();
                $evaluate = true;
            }
            if (! empty($data['events'])) {
                $this->storeEvents($machine, $data['events']);
            }
            if (! empty($data['results'])) {
                $this->applyResults($machine, $data['results']);
            }

            if ($evaluate) {
                [$state, $issues, $score] = SecurityCompliance::evaluate($status, $policy);
                $status->compliance = $state;
                $status->compliance_issues = $issues;
                $status->score = $score;
                $label = $machine->hostname ?: ($machine->device_uuid ?: 'PC #' . $machine->id);
                SecurityCompliance::applyAlerts($status, $issues, array_values(array_unique($oneShot)), $label);
            }
            $status->save();

            EndpointSecurityEvent::withoutGlobalScopes()->where('enforcement_machine_id', $machine->id)
                ->where('occurred_at', '<', now()->subDays((int) config('endpoint_security.event_retention_days', 180)))
                ->delete();
        });

        return response()->json([
            'ok' => true,
            'enabled' => true,
            'server_time' => now()->toIso8601String(),
            'redaction' => $policy['pathRedaction'],
            'intervals' => config('endpoint_security.intervals'),
            'commands' => $this->deliver($machine, $company),
        ]);
    }

    private function applyHealth(EndpointSecurityStatus $s, array $h): void
    {
        $bool = fn ($v) => is_bool($v) ? $v : null;
        $time = function ($v) {
            try {
                return is_string($v) && $v !== '' ? Carbon::parse($v) : null;
            } catch (\Throwable $e) {
                return null;
            }
        };
        $fw = (array) ($h['firewall'] ?? []);
        $providers = array_slice(array_map(fn ($p) => [
            'name' => mb_substr((string) ($p['name'] ?? ''), 0, 120),
            'active' => (bool) ($p['active'] ?? false),
            'upToDate' => $bool($p['upToDate'] ?? null),
            'defender' => (bool) ($p['defender'] ?? false),
        ], array_filter((array) ($h['installedProviders'] ?? []), 'is_array')), 0, 10);

        $type = (string) ($h['providerType'] ?? 'unknown');
        $s->fill([
            'provider' => mb_substr((string) ($h['provider'] ?? ''), 0, 120) ?: null,
            'provider_type' => in_array($type, ['defender', 'third_party', 'none', 'unknown'], true) ? $type : 'unknown',
            'installed_providers' => $providers,
            'antivirus_installed' => $bool($h['antivirusInstalled'] ?? null),
            'antivirus_enabled' => $bool($h['antivirusEnabled'] ?? null),
            'realtime_enabled' => $bool($h['realTimeProtectionEnabled'] ?? null),
            'behavior_enabled' => $bool($h['behaviorMonitoringEnabled'] ?? null),
            'ioav_enabled' => $bool($h['ioavProtectionEnabled'] ?? null),
            'running_mode' => mb_substr((string) ($h['runningMode'] ?? ''), 0, 40) ?: null,
            'signature_version' => mb_substr((string) ($h['signatureVersion'] ?? ''), 0, 40) ?: null,
            'signature_updated_at' => $time($h['signatureLastUpdated'] ?? null),
            'signature_outdated' => $bool($h['signatureOutdated'] ?? null),
            'last_quick_scan_at' => $time($h['lastQuickScan'] ?? null),
            'last_full_scan_at' => $time($h['lastFullScan'] ?? null),
            'firewall_domain' => $bool($fw['domain'] ?? null),
            'firewall_private' => $bool($fw['private'] ?? null),
            'firewall_public' => $bool($fw['public'] ?? null),
            'errors' => array_values(array_filter(array_map(fn ($e) => preg_match('/^[A-Z_]{3,50}$/', (string) $e) ? $e : null, (array) ($h['errors'] ?? [])))),
            'checked_at' => $time($h['checkedAt'] ?? null) ?? now(),
        ]);
        if (array_key_exists('threatCount', $h) && is_int($h['threatCount'])) {
            $s->threat_count = $h['threatCount'];
        }
        if (array_key_exists('activeThreatCount', $h) && is_int($h['activeThreatCount'])) {
            $s->active_threat_count = $h['activeThreatCount'];
        }
    }

    /** Full list from Defender. Returns true when a threat is newly active. */
    private function applyThreats(EnforcementMachine $m, array $threats): bool
    {
        $existing = EndpointSecurityThreat::withoutGlobalScopes()->where('enforcement_machine_id', $m->id)->get()->keyBy('threat_id');
        $seen = [];
        $newlyActive = false;
        foreach (array_slice($threats, 0, 500) as $t) {
            if (! is_array($t) || ! preg_match('/^\d{1,40}$/', (string) ($t['threatId'] ?? ''))) {
                continue;
            }
            $id = (string) $t['threatId'];
            $seen[] = $id;
            $active = (bool) ($t['active'] ?? false);
            $row = $existing[$id] ?? new EndpointSecurityThreat(['company_id' => $m->company_id, 'enforcement_machine_id' => $m->id, 'threat_id' => $id]);
            if ($active && (! $row->exists || ! $row->active)) {
                $newlyActive = true;
            }
            $row->fill([
                'threat_name' => mb_substr((string) ($t['threatName'] ?? ''), 0, 255) ?: null,
                'severity' => is_int($t['severity'] ?? null) ? max(0, min(255, $t['severity'])) : null,
                'status' => preg_match('/^[a-z_]{1,30}$/', (string) ($t['status'] ?? '')) ? $t['status'] : 'unknown',
                'active' => $active,
                'action_success' => is_bool($t['actionSuccess'] ?? null) ? $t['actionSuccess'] : null,
                'detected_at' => $this->time($t['initialDetectionTime'] ?? null),
                'status_changed_at' => $this->time($t['lastStatusChangeTime'] ?? null),
                'resources' => array_slice(array_map(fn ($r) => mb_substr((string) $r, 0, 400), (array) ($t['resources'] ?? [])), 0, 20),
            ]);
            $row->company_id = $m->company_id;
            $row->save();
        }
        // Gone from Defender's history = no longer an active threat.
        EndpointSecurityThreat::withoutGlobalScopes()->where('enforcement_machine_id', $m->id)
            ->whereNotIn('threat_id', $seen ?: ['-'])->where('active', true)->update(['active' => false]);

        return $newlyActive;
    }

    private function storeEvents(EnforcementMachine $m, array $events): void
    {
        $rows = [];
        foreach (array_slice($events, 0, 500) as $e) {
            if (! is_array($e) || ! is_int($e['recordId'] ?? null) || ! preg_match('/^[a-z_]{1,40}$/', (string) ($e['kind'] ?? ''))) {
                continue;
            }
            $rows[] = [
                'company_id' => $m->company_id, 'enforcement_machine_id' => $m->id, 'source' => 'defender',
                'record_id' => $e['recordId'], 'event_id' => is_int($e['eventId'] ?? null) ? $e['eventId'] : null,
                'kind' => $e['kind'], 'occurred_at' => $this->time($e['time'] ?? null) ?? now(),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if ($rows) {
            DB::table('endpoint_security_events')->insertOrIgnore($rows); // idempotent on (machine, record_id)
        }
    }

    private function applyResults(EnforcementMachine $m, array $results): void
    {
        $rank = ['queued' => 0, 'received' => 1, 'running' => 2, 'completed' => 3, 'failed' => 3, 'expired' => 3, 'cancelled' => 3];
        foreach (array_slice($results, 0, 100) as $r) {
            if (! is_array($r) || ! in_array($r['status'] ?? null, ['received', 'running', 'completed', 'failed'], true)) {
                continue;
            }
            $cmd = EndpointSecurityCommand::withoutGlobalScopes()->where('enforcement_machine_id', $m->id)
                ->where('uuid', (string) ($r['commandId'] ?? ''))->first();
            if (! $cmd || $rank[$cmd->status] >= 3 || $rank[$r['status']] < $rank[$cmd->status]) {
                continue; // unknown, already final, or an out-of-order older report
            }
            $code = preg_match('/^[A-Z_]{3,50}$/', (string) ($r['errorCode'] ?? '')) ? $r['errorCode'] : null;
            $cmd->fill([
                'status' => $r['status'],
                'received_at' => $cmd->received_at ?? now(),
                'started_at' => $this->time($r['startedAt'] ?? null) ?? $cmd->started_at,
                'completed_at' => in_array($r['status'], ['completed', 'failed'], true) ? ($this->time($r['completedAt'] ?? null) ?? now()) : null,
                'error_code' => $code,
                'error_message' => $code ? (EndpointSecurityController::ERRORS[$code] ?? 'The action failed on the computer.') : null,
            ])->save();

            if (in_array($cmd->status, ['completed', 'failed'], true)) {
                AuditLog::create([
                    'company_id' => $cmd->company_id, 'user_id' => $cmd->requested_by,
                    'action' => 'endpoint_security.command_' . $cmd->status,
                    'subject_type' => 'endpoint_security_command', 'subject_id' => $cmd->id,
                    'changes' => ['type' => $cmd->type, 'machine' => $m->hostname, 'error_code' => $code],
                ]);
            }
        }
    }

    /** Queued (and received-but-not-started, in case a response was lost) commands for this machine. */
    private function deliver(EnforcementMachine $m, ?Company $company): array
    {
        $base = EndpointSecurityCommand::withoutGlobalScopes()->where('enforcement_machine_id', $m->id);
        (clone $base)->whereIn('status', ['queued', 'received'])->whereNull('started_at')
            ->where('expires_at', '<', now())->update(['status' => 'expired', 'error_code' => 'COMMAND_EXPIRED',
                'error_message' => self::expiredMessage(), 'completed_at' => now()]);

        $out = [];
        foreach ((clone $base)->whereIn('status', ['queued', 'received'])->whereNull('started_at')->orderBy('id')->limit(10)->get() as $cmd) {
            if (! Entitlement::can($company, self::TYPE_CAPABILITY[$cmd->type] ?? '-')) {
                $cmd->update(['status' => 'cancelled', 'error_code' => 'FEATURE_NOT_AVAILABLE',
                    'error_message' => 'Not included in the current plan.', 'completed_at' => now()]);
                continue;
            }
            if ($cmd->status === 'queued') {
                $cmd->update(['status' => 'received', 'received_at' => now()]);
            }
            $out[] = array_filter([
                'id' => $cmd->uuid,
                'type' => $cmd->type,
                'path' => $cmd->type === 'AV_CUSTOM_SCAN' ? ($cmd->parameters['path'] ?? null) : null,
                'expiresAt' => $cmd->expires_at?->toIso8601String(),
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    private static function expiredMessage(): string
    {
        return 'The computer did not pick this up in time (offline or switched off).';
    }

    private function time($v): ?Carbon
    {
        try {
            return is_string($v) && $v !== '' && ! str_starts_with($v, '0001-') ? Carbon::parse($v) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
