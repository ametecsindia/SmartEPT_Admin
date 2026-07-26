<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeWebcamLog;
use App\Models\StorageFile;
use App\Models\WebcamPhotoAccessLog;
use App\Support\ResolvesBusinessDay;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * EPT25-05 — webcam photo viewer. The agent already stores webcam presence photos
 * (employee_webcam_logs) but there was no way to view them from the console. This
 * mirrors ScreenshotController: a company-day wall + a protected, access-logged file
 * route. Gated by the existing webcam.view permission.
 */
class WebcamController extends Controller
{
    use ScopesVisibleEmployees;
    use ResolvesBusinessDay;

    /** GET /api/reports/webcam?date= — company-wide webcam wall for a day. */
    public function companyDay(Request $request): JsonResponse
    {
        $tz = $this->bizTz($request);
        $date = $request->query('date', $this->bizToday($tz));
        $visible = $this->visibleEmployeeIds($request->user());

        $logs = EmployeeWebcamLog::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->whereDate('captured_at', $date)
            ->whereNotNull('storage_file_id')
            ->with('employee:id,first_name,last_name,employee_code')
            ->latest('captured_at')
            ->limit(600)
            ->get()
            ->map(fn ($l) => [
                'id'              => $l->id,
                'employee_id'     => $l->employee_id,
                'employee_name'   => $l->employee ? trim($l->employee->first_name . ' ' . $l->employee->last_name) : '—',
                'employee_code'   => $l->employee?->employee_code,
                'captured_at'     => $l->captured_at,
                'presence_status' => $l->presence_status,
                'face_count'      => $l->face_count,
                'trigger_reason'  => $l->trigger_reason,
                'url'             => route('webcam.file', ['webcam' => $l->id]),
            ]);

        $this->audit($request, 'EXPORT', EmployeeWebcamLog::class, null, ['scope' => 'all', 'date' => $date]);

        return response()->json(['date' => $date, 'count' => $logs->count(), 'data' => $logs]);
    }

    /** GET /api/webcam/{webcam}/file — stream the protected photo, access-logged. */
    public function file(Request $request, EmployeeWebcamLog $webcam)
    {
        $this->assertEmployeeVisible($request, $webcam->employee_id);
        $file = StorageFile::withoutGlobalScopes()->find($webcam->storage_file_id);
        abort_if(! $file, 404, 'File not found.');

        try {
            WebcamPhotoAccessLog::create([
                'company_id'             => $webcam->company_id,
                'user_id'                => $request->user()->id,
                'employee_webcam_log_id' => $webcam->id,
                'employee_id'            => $webcam->employee_id,
                'ip'                     => $request->ip(),
                'viewed_at'              => now(),
            ]);
        } catch (\Throwable $e) {
            // access logging must never block viewing
        }

        abort_unless(Storage::disk($file->storage_driver)->exists($file->storage_key), 404, 'Stored file missing.');

        return Storage::disk($file->storage_driver)->response($file->storage_key);
    }
}
