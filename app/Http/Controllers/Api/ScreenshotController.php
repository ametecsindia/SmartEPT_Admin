<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ScopesVisibleEmployees;
use App\Models\Employee;
use App\Models\EmployeeScreenshotLog;
use App\Models\ScreenshotAccessLog;
use App\Models\StorageFile;
use App\Services\PolicyResolver;
use App\Services\StorageService;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ScreenshotController extends Controller
{
    use ScopesVisibleEmployees;
    use ResolvesAgentContext;

    /**
     * POST /api/agent/screenshot-upload  (multipart)
     * Stores the image in the object store, records metadata only in the DB.
     * Rejected unless the effective screenshot policy is enabled.
     */
    public function upload(Request $request, PolicyResolver $resolver, StorageService $storage): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'    => ['required', 'string'],
            'image'          => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'captured_at'    => ['nullable', 'date'],
            'active_app'     => ['nullable', 'string', 'max:255'],
            'window_title'   => ['nullable', 'string', 'max:512'],
            'website_domain' => ['nullable', 'string', 'max:255'],
            'trigger_reason' => ['nullable', 'in:INTERVAL,RANDOM,VIOLATION,BLOCKED_APP,BLOCKED_SITE'],
        ]);

        $bundle = $resolver->bundleForEmployee($employee);
        $shot = $bundle['policies']['screenshot'] ?? null;
        abort_if(! $shot || empty($shot['enabled']), 403, 'Screenshot capture is not enabled by policy.');

        $file = $storage->storeUpload(
            $request->file('image'),
            $employee->company_id,
            $employee->id,
            'SCREENSHOT',
            $shot['retention_days'] ?? null
        );

        $log = EmployeeScreenshotLog::create([
            'company_id'          => $employee->company_id,
            'employee_id'         => $employee->id,
            'device_uuid'         => $data['device_uuid'],
            'storage_file_id'     => $file->id,
            'captured_at'         => $data['captured_at'] ?? now(),
            'active_app'          => $data['active_app'] ?? null,
            'window_title'        => $data['window_title'] ?? null,
            'website_domain'      => $data['website_domain'] ?? null,
            'trigger_reason'      => $data['trigger_reason'] ?? 'INTERVAL',
            'screenshot_policy_id' => $shot['id'] ?? null,
            'file_size_bytes'     => $file->size_bytes,
        ]);

        return response()->json(['ok' => true, 'screenshot_id' => $log->id, 'storage_file_id' => $file->id], 201);
    }

    /**
     * GET /api/reports/employee/{employee}/screenshots
     * Screenshot timeline (metadata + protected file URLs). Requires screenshot.view.
     */
    public function timeline(Request $request, Employee $employee): JsonResponse
    {
        $this->assertEmployeeVisible($request, $employee->id);
        $date = $request->query('date', now()->toDateString());

        $logs = EmployeeScreenshotLog::where('employee_id', $employee->id)
            ->whereDate('captured_at', $date)
            ->latest('captured_at')
            ->limit(500)
            ->get()
            ->map(fn ($l) => [
                'id'            => $l->id,
                'captured_at'   => $l->captured_at,
                'active_app'    => $l->active_app,
                'window_title'  => $l->window_title,
                'website_domain' => $l->website_domain,
                'trigger_reason' => $l->trigger_reason,
                'size_bytes'    => $l->file_size_bytes,
                'url'           => route('screenshots.file', ['screenshot' => $l->id]),
            ]);

        $this->audit($request, 'EXPORT', EmployeeScreenshotLog::class, null, ['employee_id' => $employee->id, 'date' => $date]);

        return response()->json(['data' => $logs]);
    }

    /**
     * GET /api/screenshots/{screenshot}/file
     * Streams the protected image and records the access (who/when/ip).
     */
    public function file(Request $request, EmployeeScreenshotLog $screenshot)
    {
        $this->assertEmployeeVisible($request, $screenshot->employee_id);
        $file = StorageFile::withoutGlobalScopes()->find($screenshot->storage_file_id);
        abort_if(! $file, 404, 'File not found.');

        ScreenshotAccessLog::create([
            'company_id'                => $screenshot->company_id,
            'user_id'                   => $request->user()->id,
            'employee_screenshot_log_id' => $screenshot->id,
            'employee_id'               => $screenshot->employee_id,
            'ip'                        => $request->ip(),
            'viewed_at'                 => now(),
        ]);

        abort_unless(Storage::disk($file->storage_driver)->exists($file->storage_key), 404, 'Stored file missing.');

        return Storage::disk($file->storage_driver)->response($file->storage_key);
    }
}
