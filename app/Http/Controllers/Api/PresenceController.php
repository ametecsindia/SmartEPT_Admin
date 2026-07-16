<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePresenceEvent;
use App\Models\EmployeeWebcamLog;
use App\Services\PolicyResolver;
use App\Services\StorageService;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PresenceController extends Controller
{
    use ResolvesAgentContext;

    /**
     * POST /api/agent/presence-event
     * Webcam presence outcome — METADATA ONLY (no image). Rejected unless presence
     * detection is enabled by the effective webcam policy.
     */
    public function event(Request $request, PolicyResolver $resolver): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'      => ['required', 'string'],
            'event_type'       => ['required', 'in:PRESENT,AWAY_FROM_SCREEN,CAMERA_BLOCKED,MULTIPLE_FACE_DETECTED,CAMERA_UNAVAILABLE,UNKNOWN'],
            'confidence_score' => ['nullable', 'numeric', 'between:0,1'],
            'started_at'       => ['required', 'date'],
            'ended_at'         => ['nullable', 'date'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'metadata'         => ['nullable', 'array'],
        ]);

        $bundle = $resolver->bundleForEmployee($employee);
        $webcam = $bundle['policies']['webcam'] ?? null;
        abort_if(! $webcam || empty($webcam['presence_enabled']), 403, 'Webcam presence is not enabled by policy.');

        // Defensive: never accept an image on the metadata endpoint.
        abort_if($request->hasFile('image'), 422, 'Presence events carry metadata only, not images.');

        $start = Carbon::parse($data['started_at']);
        $end = isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : null;

        $ev = EmployeePresenceEvent::create([
            'company_id'       => $employee->company_id,
            'employee_id'      => $employee->id,
            'device_uuid'      => $data['device_uuid'],
            'event_type'       => $data['event_type'],
            'confidence_score' => $data['confidence_score'] ?? null,
            'started_at'       => $start,
            'ended_at'         => $end,
            'duration_seconds' => $data['duration_seconds'] ?? ($end ? (int) $end->diffInSeconds($start, true) : 0),
            'metadata'         => $data['metadata'] ?? null,
        ]);

        return response()->json(['ok' => true, 'presence_id' => $ev->id], 201);
    }

    /**
     * POST /api/agent/webcam-event  (optional multipart)
     * Metadata always; a photo is accepted ONLY when the policy explicitly enables photos.
     */
    public function webcam(Request $request, PolicyResolver $resolver, StorageService $storage): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'     => ['required', 'string'],
            'captured_at'     => ['nullable', 'date'],
            'trigger_reason'  => ['nullable', 'in:INTERVAL,VIOLATION,ATTENDANCE'],
            'presence_status' => ['nullable', 'string', 'max:64'],
            'face_count'      => ['nullable', 'integer', 'min:0'],
            'image'           => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:4096'],
        ]);

        $bundle = $resolver->bundleForEmployee($employee);
        $webcam = $bundle['policies']['webcam'] ?? null;
        abort_if(! $webcam, 403, 'Webcam policy not assigned.');

        $storageFileId = null;
        if ($request->hasFile('image')) {
            abort_if(empty($webcam['photo_enabled']), 403, 'Webcam photo capture is not enabled by policy.');
            $file = $storage->storeUpload($request->file('image'), $employee->company_id, $employee->id, 'WEBCAM_PHOTO', $webcam['photo_retention_days'] ?? null);
            $storageFileId = $file->id;
        }

        $log = EmployeeWebcamLog::create([
            'company_id'      => $employee->company_id,
            'employee_id'     => $employee->id,
            'device_uuid'     => $data['device_uuid'],
            'storage_file_id' => $storageFileId,
            'captured_at'     => $data['captured_at'] ?? now(),
            'trigger_reason'  => $data['trigger_reason'] ?? 'INTERVAL',
            'presence_status' => $data['presence_status'] ?? null,
            'face_count'      => $data['face_count'] ?? 0,
            'webcam_policy_id' => $webcam['id'] ?? null,
        ]);

        return response()->json(['ok' => true, 'webcam_id' => $log->id, 'photo_stored' => (bool) $storageFileId], 201);
    }

    /**
     * GET /api/reports/employee/{employee}/presence
     * Presence timeline (metadata). Manager+ only.
     */
    public function timeline(Request $request, Employee $employee): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        $events = EmployeePresenceEvent::where('employee_id', $employee->id)
            ->whereDate('started_at', $date)
            ->latest('started_at')
            ->limit(1000)
            ->get(['id', 'event_type', 'confidence_score', 'started_at', 'ended_at', 'duration_seconds', 'metadata']);

        return response()->json(['data' => $events]);
    }
}
