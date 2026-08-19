<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePresenceEvent;
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

        return response()->json([
            'date' => $date, 'count' => $logs->count(), 'data' => $logs,
            'presence' => $this->presenceRollup($date, $visible),
        ]);
    }

    /**
     * 19-Aug-2026 (Ejaz: "webcam presence is not captured even though the webcam is connected").
     *
     * Webcam PRESENCE and webcam PHOTOS are two different streams, and this screen only ever
     * showed photos. The shipped default webcam policy is presence_enabled=true /
     * photo_enabled=false, so a tenant doing everything right saw a permanently empty wall and
     * concluded presence was broken.
     *
     * This returns the day's presence detection per employee — including CAMERA_UNAVAILABLE
     * and CAMERA_BLOCKED, which are the rows that actually diagnose a camera problem — so the
     * console can say "presence IS being captured, photos are simply off" or "the agent reports
     * the camera as unavailable", rather than showing nothing either way.
     */
    private function presenceRollup(string $date, ?array $visible): array
    {
        $rows = EmployeePresenceEvent::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->whereDate('started_at', $date)
            ->selectRaw('employee_id, event_type, COUNT(*) events, COALESCE(SUM(duration_seconds),0) secs, MAX(started_at) last_at')
            ->groupBy('employee_id', 'event_type')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = Employee::whereIn('id', $rows->pluck('employee_id')->unique())
            ->get(['id', 'first_name', 'last_name', 'employee_code'])->keyBy('id');

        $byEmployee = [];
        foreach ($rows as $r) {
            $e = $names[$r->employee_id] ?? null;
            $byEmployee[$r->employee_id] ??= [
                'employee_id'   => $r->employee_id,
                'employee_name' => $e ? trim($e->first_name . ' ' . $e->last_name) : '—',
                'employee_code' => $e?->employee_code,
                'last_at'       => null,
                'by_status'     => [],
            ];
            $byEmployee[$r->employee_id]['by_status'][$r->event_type] = [
                'events'  => (int) $r->events,
                'seconds' => (int) $r->secs,
            ];
            if (! $byEmployee[$r->employee_id]['last_at'] || $r->last_at > $byEmployee[$r->employee_id]['last_at']) {
                $byEmployee[$r->employee_id]['last_at'] = $r->last_at;
            }
        }

        return array_values($byEmployee);
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
