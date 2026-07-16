<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\StorageFile;
use Illuminate\Http\JsonResponse;
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
