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
    public function auditLogs(Request $request): JsonResponse
    {
        $user = $request->user();

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
            ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json($logs);
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

        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date'   => ['required', 'date', 'after_or_equal:from_date'],
            'targets'   => ['required', 'array', 'min:1'],
            'targets.*' => ['in:screenshots,activity,app_usage,website_usage,presence'],
            'keep_violation_evidence' => ['boolean'],
            'delete_violation_records' => ['boolean'],
        ]);

        $companyId = $user->company_id;
        abort_unless($companyId, 422, 'Sign in as a company admin to run a cleanup.');

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
