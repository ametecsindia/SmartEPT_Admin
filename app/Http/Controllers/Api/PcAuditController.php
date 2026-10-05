<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EmployeeDevice;
use App\Models\EnforcementMachine;
use App\Models\PcAuditReport;
use App\Models\User;
use App\Services\EndpointSecurity\Entitlement;
use App\Services\HierarchyService;
use App\Services\PcAudit\PcAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * 04-Oct-2026 — PC Audit Log (Enforcer + Commander; plan gate on the routes).
 *
 * Console:  one "View logs" per PC (JSON + CSV for a date range) and "Run audit for all PCs",
 *           which builds one CSV in the background and lists it under Reports when done.
 * Ingest:   POST /api/enforcer/pc-audit/sync   (SmartEPT Agent Service: software, USB/devices,
 *                                               downloads, files to USB, network shares)
 *           POST /api/agent/pc-audit/clicks    (agent: what was clicked)
 */
class PcAuditController extends Controller
{
    /** What the service may send. Anything else is dropped. */
    private const SERVICE_KINDS = ['software_installed', 'software_removed', 'software_updated', 'windows_update',
        'usb_storage', 'device_connected', 'download', 'file_to_usb', 'share_connected'];

    private const MAX_DAYS = 92; // ponytail: one quarter per request; widen if clients ask for more

    // ---- console ------------------------------------------------------------

    public function devices(Request $request): JsonResponse
    {
        return response()->json(['data' => self::visibleDevices($request->user())->map(fn ($d) => [
            'device_uuid' => $d->device_uuid,
            'device' => $d->computer_name ?: $d->device_uuid,
            'employee' => $d->employee ? trim($d->employee->first_name . ' ' . $d->employee->last_name) : null,
            'employee_code' => $d->employee?->employee_code,
            'os' => $d->os_version,
            'agent_version' => $d->app_version,
            'service_version' => $d->service_version,
            'last_seen' => $d->last_heartbeat_at?->toIso8601String(),
        ])->values()]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        [$device, $from, $to, $only] = $this->target($request, $uuid);
        $rows = PcAuditLog::forDevice((int) $device->company_id, $uuid, $from, $to, $only);
        $counts = array_count_values(array_column($rows, 'category'));
        $names = self::names(array_column($rows, 'employee_id'));
        $shown = array_slice($rows, -20000); // newest 20000 on screen (the timeline renders only open groups); the CSV has everything

        return response()->json([
            'device' => $device->computer_name ?: $uuid,
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'total' => count($rows),
            'shown' => count($shown),
            'counts' => $counts,
            'categories' => PcAuditLog::CATEGORIES,
            'data' => array_map(fn ($r) => $r + ['employee' => $names[$r['employee_id']] ?? null], array_reverse($shown)),
        ]);
    }

    public function csv(Request $request, string $uuid): Response
    {
        [$device, $from, $to, $only] = $this->target($request, $uuid);
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $device->computer_name ?: 'pc');

        return response()->streamDownload(function () use ($device, $uuid, $from, $to, $only) {
            $out = fopen('php://output', 'w');
            self::writeCsv($out, [[$device, PcAuditLog::forDevice((int) $device->company_id, $uuid, $from, $to, $only)]]);
            fclose($out);
        }, "pc-audit-{$name}-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function startReport(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $user = $request->user();
        if (PcAuditReport::where('company_id', $user->company_id)->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHours(2))->exists()) {
            return response()->json(['error' => ['code' => 'REPORT_RUNNING', 'message' => 'An all-PC audit is already running. It will appear below when done.']], 409);
        }
        $report = PcAuditReport::create(['company_id' => $user->company_id, 'requested_by' => $user->id,
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'status' => 'queued']);

        // Runs after the response is sent (php-fpm: fastcgi_finish_request), so the admin is
        // never kept waiting and no queue worker has to be running for it.
        dispatch(fn () => self::runReport($report->id))->afterResponse();

        return response()->json(['data' => self::reportRow($report)], 202);
    }

    public function reports(Request $request): JsonResponse
    {
        // A run whose PHP process died never finishes on its own.
        $co = $request->user()->company_id; // explicit: BelongsToCompany does not narrow a Super Admin
        PcAuditReport::where('company_id', $co)->whereIn('status', ['queued', 'running'])->where('created_at', '<', now()->subHours(2))
            ->update(['status' => 'failed', 'error' => 'Stopped before finishing — run it again.']);

        return response()->json(['data' => PcAuditReport::with('requester:id,name')->where('company_id', $co)->latest('id')->limit(20)->get()
            ->map(fn ($r) => self::reportRow($r))]);
    }

    public function download(Request $request, PcAuditReport $report): Response
    {
        abort_unless($report->company_id === $request->user()->company_id, 404, 'Report file not found.');
        abort_unless($report->status === 'done' && $report->file && is_file(storage_path('app/' . $report->file)), 404, 'Report file not found.');

        return response()->download(storage_path('app/' . $report->file),
            "pc-audit-all-pcs-{$report->date_from->toDateString()}-to-{$report->date_to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The background half of startReport(). Public static so it is testable on its own. */
    public static function runReport(int $id): void
    {
        $report = PcAuditReport::withoutGlobalScopes()->find($id);
        if (! $report || $report->status !== 'queued') {
            return;
        }
        $report->update(['status' => 'running', 'started_at' => now()]);
        try {
            @set_time_limit(0);
            $user = User::find($report->requested_by);
            abort_unless($user, 403);
            $from = Carbon::parse($report->date_from)->startOfDay();
            $to = Carbon::parse($report->date_to)->endOfDay();
            $rel = 'pc-audit/' . $report->company_id . '/pc-audit-' . $report->id . '.csv';
            @mkdir(dirname(storage_path('app/' . $rel)), 0775, true);
            $out = fopen(storage_path('app/' . $rel), 'w');
            $devices = self::visibleDevices($user);
            $rows = 0;
            self::writeCsv($out, (function () use ($devices, $from, $to, &$rows) {
                foreach ($devices as $d) { // one PC at a time — memory stays at one PC's worth
                    $r = PcAuditLog::forDevice((int) $d->company_id, $d->device_uuid, $from, $to);
                    $rows += count($r);
                    yield [$d, $r];
                }
            })());
            fclose($out);
            $report->update(['status' => 'done', 'file' => $rel, 'devices' => $devices->count(), 'rows' => $rows, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
            $report->update(['status' => 'failed', 'error' => mb_substr($e->getMessage() ?: 'Failed', 0, 250), 'finished_at' => now()]);
        }
    }

    // ---- ingest ---------------------------------------------------------------

    /** POST /api/enforcer/pc-audit/sync — the SmartEPT Agent Service (LocalSystem). */
    public function serviceSync(Request $request): JsonResponse
    {
        $machine = $request->user();
        abort_unless($machine instanceof EnforcementMachine && $machine->tokenCan('enforcer'), 403, 'Enforcer credential required.');
        abort_unless($machine->isActive(), 403, 'This endpoint has been revoked.');
        $company = Company::find($machine->company_id);
        if (! Entitlement::can($company, 'pc_audit')) {
            return response()->json(['ok' => true, 'enabled' => false]);
        }
        $data = $request->validate(['events' => ['nullable', 'array', 'max:2000']]);
        $smarteptBlocks = (bool) ($company->block_removable_storage ?? false);
        // 05-Oct-2026: file under the agent device on this PC NOW (see EnforcementMachine::liveDeviceUuid).
        $uuid = EnforcementMachine::liveDeviceUuid((int) $machine->company_id, $machine->hostname, $machine->device_uuid) ?: 'machine-' . $machine->id;

        $rows = [];
        foreach ((array) ($data['events'] ?? []) as $e) {
            if (! is_array($e) || ! in_array($e['kind'] ?? null, self::SERVICE_KINDS, true) || ! ($at = self::time($e['time'] ?? null))) {
                continue;
            }
            $detail = array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 500) : null, array_slice((array) ($e['detail'] ?? []), 0, 12, true));
            $outcome = null;
            if ($e['kind'] === 'usb_storage') {
                // 05-Oct-2026: a drive Windows refused (driver disabled -> error_code) is a blocked attempt too.
                $blocked = ($detail['policy'] ?? '') === 'deny_all' || ($detail['usbstor_disabled'] ?? '') === '1' || ($detail['error_code'] ?? '') !== '';
                $outcome = ! $blocked ? 'Allowed' : ($smarteptBlocks ? 'Blocked by SmartEPT' : 'Blocked by another policy on this PC (not SmartEPT)');
            } elseif ($e['kind'] === 'device_connected') {
                $outcome = ($detail['error_code'] ?? '') === '' ? 'Allowed' : 'Blocked — Windows refused the device (code ' . $detail['error_code'] . ')';
            }
            $rows[] = self::row($machine->company_id, $uuid, $machine->signed_in_employee_id, $e['kind'], $at, (string) ($e['title'] ?? ''), $detail, $outcome);
        }
        self::store($rows, $machine->company_id, $uuid);

        return response()->json(['ok' => true, 'enabled' => true, 'stored' => count($rows)]);
    }

    /** POST /api/agent/pc-audit/clicks — the agent, as the signed-in employee. */
    public function agentClicks(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('agent'), 403, 'Agent token required.');
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'max:64'],
            'clicks' => ['required', 'array', 'max:2000'],
        ]);
        // Only the PC this token was issued for (token name = "device:<uuid>", see EmployeeDevice::revokeAgentToken).
        abort_unless($request->user()->currentAccessToken()?->name === 'device:' . $data['device_uuid'], 403, 'Not this device.');
        $device = EmployeeDevice::where('device_uuid', $data['device_uuid'])->firstOrFail();
        if (! Entitlement::can(Company::find($device->company_id), 'pc_audit')) {
            return response()->json(['error' => ['code' => 'FEATURE_NOT_AVAILABLE', 'message' => 'PC Audit Log is available in SmartEPT Enforcer and Commander.']], 403);
        }
        $rows = [];
        foreach ($data['clicks'] as $c) {
            if (! is_array($c) || ! ($at = self::time($c['at'] ?? null))) {
                continue;
            }
            $s = fn ($k, $n) => mb_substr(trim((string) ($c[$k] ?? '')), 0, $n);
            $rows[] = self::row($device->company_id, $device->device_uuid, $device->employee_id, 'click', $at, $s('target', 200),
                array_filter(['app' => $s('app', 120), 'window' => $s('window', 300), 'control' => $s('control', 60),
                    'button' => ($c['button'] ?? '') === 'right' ? 'right' : 'left']));
        }
        self::store($rows, $device->company_id, $device->device_uuid);

        return response()->json(['ok' => true, 'stored' => count($rows)]);
    }

    // ---- helpers --------------------------------------------------------------

    private static function row(int $companyId, string $uuid, $employeeId, string $kind, Carbon $at, string $title, array $detail, ?string $outcome = null): array
    {
        $title = mb_substr($title, 0, 255);

        return ['company_id' => $companyId, 'device_uuid' => $uuid, 'employee_id' => $employeeId ?: null, 'kind' => $kind,
            'occurred_at' => $at->toDateTimeString(), 'title' => $title, 'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'outcome' => $outcome, 'created_at' => now(),
            'fingerprint' => sha1($kind . '|' . $at->format('Y-m-d H:i:s.v') . '|' . $title . '|' . json_encode($detail))];
    }

    private static function store(array $rows, int $companyId, string $uuid): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pc_audit_events')->insertOrIgnore($chunk); // a resent batch is ignored, never doubled
        }
        if ($rows && random_int(1, 50) === 1) { // ponytail: retention swept on ~2% of writes, per PC
            DB::table('pc_audit_events')->where('company_id', $companyId)->where('device_uuid', $uuid)
                ->where('occurred_at', '<', now()->subDays((int) config('endpoint_security.pc_audit_retention_days', 180)))->delete();
        }
    }

    /** Endpoint times arrive in UTC; the database holds the company clock (see EndpointSecuritySyncController::time). */
    private static function time($v): ?Carbon
    {
        try {
            return is_string($v) && $v !== '' ? Carbon::parse($v)->setTimezone(config('app.timezone')) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function range(Request $request): array
    {
        $d = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:200'],
        ]);
        $from = Carbon::parse($d['from'] ?? now()->toDateString())->startOfDay();
        $to = Carbon::parse($d['to'] ?? $from->toDateString())->endOfDay();
        abort_if($from->diffInDays($to) >= self::MAX_DAYS, 422, 'Pick a range of ' . self::MAX_DAYS . ' days or less.');

        return [$from, $to];
    }

    /** [device, from, to, categories|null] — 404 when the PC is outside the caller's company or scope. */
    private function target(Request $request, string $uuid): array
    {
        [$from, $to] = $this->range($request);
        $device = self::visibleDevices($request->user())->firstWhere('device_uuid', $uuid);
        abort_unless($device, 404, 'PC not found.');
        $only = array_values(array_intersect(explode(',', (string) $request->query('category')), array_keys(PcAuditLog::CATEGORIES)));

        return [$device, $from, $to, $only ?: null];
    }

    private static function visibleDevices(User $user)
    {
        $visible = app(HierarchyService::class)->visibleEmployeeIds($user);

        return EmployeeDevice::withoutGlobalScopes()->with('employee:id,first_name,last_name,employee_code')
            ->where('company_id', $user->company_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible ?: [0]))
            ->orderBy('computer_name')->get();
    }

    private static function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids ? DB::table('employees')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($e) => [$e->id => trim($e->first_name . ' ' . $e->last_name)])->all() : [];
    }

    /** @param iterable<array{0:EmployeeDevice,1:array}> $perDevice */
    private static function writeCsv($out, iterable $perDevice): void
    {
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 correctly with a BOM
        fputcsv($out, ['Date / Time', 'PC', 'Employee', 'Category', 'Event', 'Details', 'Outcome']);
        foreach ($perDevice as [$device, $rows]) {
            $names = self::names(array_column($rows, 'employee_id'));
            $pc = $device->computer_name ?: $device->device_uuid;
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, [ // CSV injection guard
                    $r['at'], $pc, $names[$r['employee_id']] ?? '', PcAuditLog::CATEGORIES[$r['category']] ?? $r['category'],
                    $r['event'], $r['detail'], $r['outcome']]));
            }
        }
    }

    private static function reportRow(PcAuditReport $r): array
    {
        return ['id' => $r->id, 'status' => $r->status, 'from' => $r->date_from->toDateString(), 'to' => $r->date_to->toDateString(),
            'devices' => $r->devices, 'rows' => $r->rows, 'error' => $r->error, 'requested_by' => $r->requester?->name,
            'requested_at' => $r->created_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String()];
    }
}
