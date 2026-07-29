<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAppUsageLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeePresenceEvent;
use App\Models\EmployeeScreenshotLog;
use App\Models\EmployeeWebsiteUsageLog;
use App\Models\StorageFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * R2-4 ops surface: the write-only audit trail becomes readable, storage
 * growth per company becomes visible, and backups can be listed/run on demand.
 */
class OpsController extends Controller
{
    /** GET /api/audit-logs — filterable, tenant-scoped viewer for the audit trail. */
    /** QA Phase 6 (B13): hard cap so an over-wide range can't table-scan the log. */
    public const AUDIT_MAX_RANGE_DAYS = 92;

    public function auditLogs(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->auditRange($request, $user);

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            // Company admins see their company's rows PLUS rows written by their
            // own users pre-auth (e.g. LOGIN, where no company is attached yet).
            ->when($user->company_id, fn ($q) => $q->where(function ($qq) use ($user) {
                $qq->where('company_id', $user->company_id)
                    ->orWhereIn('user_id', \App\Models\User::where('company_id', $user->company_id)->pluck('id'));
            }))
            ->when($request->action, fn ($q, $v) => $q->where('action', 'like', '%' . $v . '%'))
            ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('subject_type'), fn ($q, $v) => $q->where('subject_type', 'like', '%' . $v . '%'))
            ->when($request->query('ip'), fn ($q, $v) => $q->where('ip', $v))
            // QA Phase 6 (B13): honour the EXACT datetime bounds the caller sent — never
            // silently collapse a set range to "the last hour" (that default lives only in
            // the console when nothing is chosen). Bounds are in the org timezone.
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 50))
            ->withQueryString();

        return response()->json($logs);
    }

    /**
     * QA Phase 6 (B13): resolve [from, to] as org-timezone datetimes. A bare date
     * (YYYY-MM-DD) spans the whole local day; a full datetime is honoured to the second.
     * A range wider than AUDIT_MAX_RANGE_DAYS is clamped (from = to − cap) to bound the
     * scan — never replaced with a one-hour default. Returns a Carbon|null pair.
     */
    private function auditRange(Request $request, $user): array
    {
        $tz = $user->company?->timezone ?: config('app.timezone', 'UTC');
        $parse = function ($v, bool $isEnd) use ($tz) {
            if (! $v) {
                return null;
            }
            try {
                $c = Carbon::parse($v, $tz);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $v))) {
                    return $isEnd ? $c->endOfDay() : $c->startOfDay();
                }
                return $c;
            } catch (\Throwable $e) {
                return null;
            }
        };

        $from = $parse($request->query('from'), false);
        $to   = $parse($request->query('to'), true);

        if ($from && $to && $from->diffInDays($to) > self::AUDIT_MAX_RANGE_DAYS) {
            $from = $to->clone()->subDays(self::AUDIT_MAX_RANGE_DAYS);
        }

        return [$from, $to];
    }

    /** GET /api/ops/storage-usage — screenshots/webcam bytes per company. */
    public function storageUsage(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = StorageFile::query()
            ->when($user->company_id, fn ($q) => $q->where('company_id', $user->company_id))
            ->selectRaw('company_id, count(*) as files, sum(size_bytes) as bytes')
            ->groupBy('company_id')
            ->get();

        $companies = Company::whereIn('id', $rows->pluck('company_id'))->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'company_id' => $r->company_id,
                'company' => $companies[$r->company_id] ?? ('#' . $r->company_id),
                'files' => (int) $r->files,
                'bytes' => (int) $r->bytes,
                'human' => $this->human((int) $r->bytes),
            ])->values(),
        ]);
    }

    /**
     * POST /api/ops/storage-cleanup — bulk delete evidence & logs in a date
     * range (Ejaz, 17-Jul). Company-scoped, role-gated, fully audit-logged.
     *
     * Rules baked in:
     * - keep_violation_evidence (DEFAULT ON): violation-triggered screenshots
     *   are excluded from deletion — evidence survives routine cleanups.
     * - delete_violation_records must be sent EXPLICITLY to remove compliance
     *   events (and with it, their screenshots if screenshots are targeted).
     * - Attendance, breaks and payroll-feeding data are NEVER touched here.
     * - The audit trail itself is NEVER deleted — accountability is permanent.
     */
    public function storageCleanup(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;
        abort_unless($companyId, 422, 'Sign in as a company admin to run a cleanup.');

        // Part D: two deletion modes. 'selected_ids' deletes explicitly-chosen screenshots and
        // must NOT require a date range; 'date_range' is the original bulk-by-range cleanup.
        // Conditional validation keyed on deletion_mode (fixes "the from date field is required").
        if ($request->input('deletion_mode') === 'selected_ids') {
            $data = $request->validate([
                'deletion_mode'     => ['required', 'in:selected_ids,date_range'],
                'screenshot_ids'    => ['required', 'array', 'min:1'],
                'screenshot_ids.*'  => ['integer'],
                'confirmation_text' => ['required', 'in:DELETE'],
            ]);

            return $this->deleteSelectedScreenshots($request, $companyId, $data['screenshot_ids']);
        }

        $data = $request->validate([
            'deletion_mode' => ['nullable', 'in:selected_ids,date_range'],
            'from_date' => ['required', 'date'],
            'to_date'   => ['required', 'date', 'after_or_equal:from_date'],
            'targets'   => ['required', 'array', 'min:1'],
            'targets.*' => ['in:screenshots,activity,app_usage,website_usage,presence'],
            'keep_violation_evidence' => ['boolean'],
            'delete_violation_records' => ['boolean'],
        ]);

        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'])->endOfDay();
        $keepViolEvidence = $request->boolean('keep_violation_evidence', true);
        $deleteViolRecords = $request->boolean('delete_violation_records', false);
        $violTriggers = ['VIOLATION', 'BLOCKED_APP', 'BLOCKED_SITE'];

        $result = [];

        if (in_array('screenshots', $data['targets'], true)) {
            $rows = 0; $files = 0; $bytes = 0;
            $q = EmployeeScreenshotLog::where('company_id', $companyId)
                ->whereBetween('captured_at', [$from, $to]);
            if ($keepViolEvidence && ! $deleteViolRecords) {
                $q->whereNotIn('trigger_reason', $violTriggers);
            }
            $q->orderBy('id')->chunkById(200, function ($logs) use (&$rows, &$files, &$bytes) {
                foreach ($logs as $log) {
                    if ($log->storage_file_id) {
                        $file = StorageFile::find($log->storage_file_id);
                        if ($file) {
                            try {
                                Storage::disk($file->storage_driver ?: 'local')->delete($file->storage_key);
                            } catch (\Throwable $e) { /* row still goes; orphan file swept later */ }
                            $bytes += (int) $file->size_bytes;
                            $files++;
                            $file->delete();
                        }
                    }
                    $log->delete();
                    $rows++;
                }
            });
            $result['screenshots'] = ['rows' => $rows, 'files' => $files, 'bytes' => $bytes, 'human' => $this->human($bytes)];
        }

        $plain = [
            'activity'      => [EmployeeActivityEvent::class, 'started_at'],
            'app_usage'     => [EmployeeAppUsageLog::class, 'start_at'],
            'website_usage' => [EmployeeWebsiteUsageLog::class, 'start_at'],
            'presence'      => [EmployeePresenceEvent::class, 'started_at'],
        ];
        foreach ($plain as $key => [$model, $col]) {
            if (! in_array($key, $data['targets'], true)) {
                continue;
            }
            $result[$key] = ['rows' => $model::where('company_id', $companyId)
                ->whereBetween($col, [$from, $to])->delete()];
        }

        if ($deleteViolRecords) {
            $result['violations'] = ['rows' => EmployeeComplianceEvent::where('company_id', $companyId)
                ->whereBetween('started_at', [$from, $to])->delete()];
        }

        $this->audit($request, 'STORAGE_CLEANUP', null, null, [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'targets' => $data['targets'],
            'keep_violation_evidence' => $keepViolEvidence,
            'delete_violation_records' => $deleteViolRecords,
            'result' => $result,
        ]);

        return response()->json(['ok' => true, 'result' => $result]);
    }

    /**
     * Part D — permanently delete explicitly-selected screenshots (image file + thumbnail row),
     * tenant-scoped (Part E). A file that will not delete leaves its DB row intact so storage and
     * DB stay in sync and the admin can retry; it is reported as a failure, never a silent success.
     */
    private function deleteSelectedScreenshots(Request $request, int $companyId, array $ids): JsonResponse
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $requested = count($ids);
        $deleted = 0; $failed = 0; $bytes = 0; $reasons = [];

        EmployeeScreenshotLog::where('company_id', $companyId) // tenant guard
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->chunkById(200, function ($logs) use (&$deleted, &$failed, &$bytes, &$reasons) {
                foreach ($logs as $log) {
                    $file = $log->storage_file_id ? StorageFile::find($log->storage_file_id) : null;
                    if ($file) {
                        try {
                            Storage::disk($file->storage_driver ?: 'local')->delete($file->storage_key);
                        } catch (\Throwable $e) {
                            $failed++;
                            $reasons[] = 'shot ' . $log->id . ': ' . $e->getMessage();
                            continue; // keep the row so DB/storage stay in sync + it can be retried
                        }
                        $bytes += (int) $file->size_bytes;
                        $file->delete();
                    }
                    $log->delete();
                    $deleted++;
                }
            });

        $this->audit($request, 'SCREENSHOT_DELETE', null, null, [
            'mode' => 'selected_ids', 'requested' => $requested,
            'deleted' => $deleted, 'failed' => $failed, 'bytes' => $bytes,
        ]);

        return response()->json([
            'success'                 => $failed === 0,
            'requested_count'         => $requested,
            'deleted_count'           => $deleted,
            'failed_count'            => $failed,
            'storage_reclaimed_bytes' => $bytes,
            'storage_reclaimed_human' => $this->human($bytes),
            'fail_reasons'            => array_slice($reasons, 0, 20),
        ]);
    }

    /** GET /api/ops/retention — the company's auto-cleanup schedule parameters. */
    public function retention(Request $request): JsonResponse
    {
        $c = Company::findOrFail($request->user()->company_id);
        return response()->json(['data' => [
            'auto_cleanup_enabled' => (bool) ($c->auto_cleanup_enabled ?? true),
            'data_retention_days' => (int) ($c->data_retention_days ?: 90),
            'retention_screenshots_days' => $c->retention_screenshots_days,
            'retention_activity_days' => $c->retention_activity_days,
            'retention_usage_days' => $c->retention_usage_days,
            'retention_violation_days' => $c->retention_violation_days,
            'retention_keep_violation_evidence' => (bool) ($c->retention_keep_violation_evidence ?? true),
            'runs_at' => '02:00 nightly',
        ]]);
    }

    /** PUT /api/ops/retention — save the auto-cleanup parameters. */
    public function updateRetention(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_cleanup_enabled' => ['boolean'],
            'data_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'retention_screenshots_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'retention_activity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'retention_usage_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'retention_violation_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'retention_keep_violation_evidence' => ['boolean'],
        ]);

        $c = Company::findOrFail($request->user()->company_id);
        $c->update($data);
        $this->audit($request, 'UPDATE', Company::class, $c->id, ['retention' => $data]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/ops/purge-run — run the retention purge now.
     * dry_run=true previews the row counts without deleting anything.
     */
    public function runPurge(Request $request): JsonResponse
    {
        $dry = $request->boolean('dry_run', true);
        \Illuminate\Support\Facades\Artisan::call('smartept:purge-expired', $dry ? ['--dry-run' => true] : []);
        $output = \Illuminate\Support\Facades\Artisan::output();
        // Keep only this company's lines for a clean, scoped preview.
        $code = Company::find($request->user()->company_id)?->code;
        $lines = array_values(array_filter(explode("\n", trim($output)),
            fn ($l) => $l !== '' && ($code === null || str_contains($l, $code) || str_contains($l, 'media') || str_contains($l, 'Expired'))));
        $this->audit($request, $dry ? 'PURGE_PREVIEW' : 'PURGE_RUN', null, null, ['lines' => count($lines)]);

        return response()->json(['ok' => true, 'dry_run' => $dry, 'lines' => $lines]);
    }

    /** GET /api/gate/policy — Gate-to-PC (USP) company setting. */
    public function gatePolicy(Request $request): JsonResponse
    {
        $c = Company::findOrFail($request->user()->company_id);
        return response()->json(['data' => [
            'gate_enabled' => (bool) ($c->gate_enabled ?? false),
            'gate_grace_minutes' => (int) ($c->gate_grace_minutes ?? 0),
        ]]);
    }

    /** PUT /api/gate/policy — turn Gate-to-PC on/off + grace window. */
    public function updateGatePolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gate_enabled' => ['boolean'],
            'gate_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
        ]);
        $c = Company::findOrFail($request->user()->company_id);
        $c->update($data);
        $this->audit($request, 'UPDATE', Company::class, $c->id, ['gate' => $data]);

        return response()->json(['ok' => true]);
    }

    /** GET /api/ops/agent-lock — exit/uninstall lock status for the admin UI (never returns the plaintext). */
    public function agentLock(Request $request): JsonResponse
    {
        $c = Company::findOrFail($request->user()->company_id);

        return response()->json(['data' => [
            'enabled'      => (bool) ($c->agent_exit_lock_enabled ?? false),
            'password_set' => filled($c->agent_exit_password),
        ]]);
    }

    /** PUT /api/ops/agent-lock — set/clear the agent exit & uninstall password. */
    public function updateAgentLock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'  => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:4', 'max:64'],
            'clear'    => ['nullable', 'boolean'],
        ]);

        $c = Company::findOrFail($request->user()->company_id);
        $c->agent_exit_lock_enabled = (bool) $data['enabled'];
        if (! empty($data['clear'])) {
            $c->agent_exit_password = null;
            $c->agent_exit_lock_enabled = false;
        } elseif (filled($data['password'] ?? null)) {
            $c->agent_exit_password = $data['password'];
        }

        if ($c->agent_exit_lock_enabled && blank($c->agent_exit_password)) {
            return response()->json(['message' => 'Set a password before enabling the exit & uninstall lock.'], 422);
        }

        $c->save();
        $this->audit($request, 'UPDATE', Company::class, $c->id, [
            'agent_exit_lock_enabled' => $c->agent_exit_lock_enabled,
            'password_changed' => filled($data['password'] ?? null) || ! empty($data['clear']),
        ]);

        return response()->json(['ok' => true]);
    }

    /** GET /api/ops/backups — newest first. */
    public function backups(): JsonResponse
    {
        $files = collect(glob(storage_path('app/backups/smartept-backup-*.sql.gz')) ?: [])
            ->map(fn ($p) => [
                'name' => basename($p),
                'bytes' => filesize($p),
                'human' => $this->human(filesize($p)),
                'created_at' => date('Y-m-d H:i:s', filemtime($p)),
            ])
            ->sortByDesc('name')
            ->values();

        return response()->json(['data' => $files]);
    }

    /** POST /api/ops/backup — run a backup right now (also scheduled nightly). */
    public function runBackup(Request $request): JsonResponse
    {
        Artisan::call('smartept:backup-database');

        $this->audit($request, 'RUN_BACKUP');

        return response()->json(['ok' => true, 'output' => trim(Artisan::output())]);
    }

    private function human(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
