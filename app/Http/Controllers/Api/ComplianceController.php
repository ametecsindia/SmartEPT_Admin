<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ScopesVisibleEmployees;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeScreenshotLog;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplianceController extends Controller
{
    use ScopesVisibleEmployees;
    use ResolvesAgentContext;

    /**
     * POST /api/agent/compliance-event
     * Instant violation from the agent (blocked app/site opened, etc.). Stored in the
     * unified stream and (later milestone) fanned out to webhooks + manager alerts.
     */
    public function store(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'         => ['required', 'string'],
            'event_type'          => ['required', 'string', 'max:64'],
            'event_category'      => ['required', 'in:APP,WEBSITE,NETWORK,WEBCAM,USB,DEVICE,AGENT'],
            'severity'            => ['nullable', 'in:LOW,MEDIUM,HIGH,CRITICAL'],
            'description'         => ['nullable', 'string', 'max:255'],
            'detected_value'      => ['nullable', 'string', 'max:255'],
            'expected_value'      => ['nullable', 'string', 'max:255'],
            'action_taken'        => ['nullable', 'string', 'max:255'],
            'screenshot_captured' => ['nullable', 'boolean'],
            'started_at'          => ['nullable', 'date'],
            'metadata'            => ['nullable', 'array'],
            // QA Phase 5 (B10): correlation id shared with the evidence screenshot.
            'client_event_uuid'   => ['nullable', 'string', 'max:64'],
        ]);

        $event = EmployeeComplianceEvent::create([
            'company_id'          => $employee->company_id,
            'employee_id'         => $employee->id,
            'device_uuid'         => $data['device_uuid'],
            'event_type'          => $data['event_type'],
            'event_category'      => $data['event_category'],
            'severity'            => $data['severity'] ?? 'MEDIUM',
            'description'         => $data['description'] ?? null,
            'detected_value'      => $data['detected_value'] ?? null,
            'expected_value'      => $data['expected_value'] ?? null,
            'action_taken'        => $data['action_taken'] ?? null,
            'screenshot_captured' => $data['screenshot_captured'] ?? false,
            'started_at'          => $data['started_at'] ?? now(),
            'metadata'            => $data['metadata'] ?? null,
            'client_event_uuid'   => $data['client_event_uuid'] ?? null,
        ]);

        // QA Phase 5 (B10): the shot and the event are two separate posts arriving in
        // either order — link back any already-stored violation screenshot that shares
        // this correlation id (or landed in the evidence window) but isn't yet linked.
        $this->linkScreenshotsToEvent($employee, $event);

        return response()->json(['ok' => true, 'compliance_event_id' => $event->id], 201);
    }

    /** QA Phase 5 (B10): back-link violation screenshots to a just-stored event. */
    private function linkScreenshotsToEvent(Employee $employee, EmployeeComplianceEvent $event): void
    {
        try {
            $window = (int) (\App\Models\Company::find($employee->company_id)->evidence_window_seconds ?? 120);
            $at = $event->started_at ?? now();

            \App\Models\EmployeeScreenshotLog::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->whereNull('violation_id')
                ->whereIn('trigger_reason', ['VIOLATION', 'BLOCKED_APP', 'BLOCKED_SITE'])
                ->when($event->client_event_uuid, fn ($q) => $q->where('client_event_uuid', $event->client_event_uuid))
                ->when(! $event->client_event_uuid, fn ($q) => $q
                    ->whereBetween('captured_at', [$at->clone()->subSeconds($window), $at->clone()->addSeconds($window)]))
                ->update(['violation_id' => $event->id]);
        } catch (\Throwable $e) {
            // best-effort correlation
        }
    }

    /**
     * GET /api/violations/{event}/evidence  (permission: evidence.view)
     * Returns ONLY the screenshots linked to this violation — server-side tenant +
     * permission enforced, so changing the id/URL can't reach another company's data.
     * If the evidence has been purged by retention, reports it as unavailable/EXPIRED.
     */
    public function evidence(Request $request, int $event): JsonResponse
    {
        $user = $request->user();

        $ev = EmployeeComplianceEvent::withoutGlobalScopes()
            ->with('employee:id,first_name,last_name,employee_code')
            ->find($event);
        abort_if(! $ev, 404, 'Violation not found.');
        // Tenant guard: reject a cross-tenant id outright (never leak another company's evidence).
        abort_if(! $user->isSuperAdmin() && $ev->company_id !== $user->company_id, 403, 'Outside your tenant.');

        $shots = \App\Models\EmployeeScreenshotLog::withoutGlobalScopes()
            ->where('company_id', $ev->company_id)
            ->where('violation_id', $ev->id)
            ->orderBy('captured_at')
            ->get();

        // Only shots whose stored file still exists count as available evidence.
        $available = $shots->filter(fn ($s) => $s->storage_file_id !== null);

        $violation = [
            'id'             => $ev->id,
            'event_type'     => $ev->event_type,
            'event_category' => $ev->event_category,
            'severity'       => $ev->severity,
            'rule_triggered' => $ev->description,
            'detected_value' => $ev->detected_value,
            'device_uuid'    => $ev->device_uuid,
            'occurred_at'    => $ev->started_at?->toDateTimeString(),
            'employee'       => [
                'id'   => $ev->employee_id,
                'name' => $ev->employee?->fullName(),
                'code' => $ev->employee?->employee_code,
            ],
        ];

        if ($available->isEmpty()) {
            // EPT25-04: distinguish the three real causes instead of always blaming retention.
            if ($shots->isNotEmpty()) {
                // Rows exist but none has a stored file → the shot was captured on the device
                // yet never reached server storage (an upload/storage error), NOT a purge.
                $reason  = 'CAPTURE_FAILED';
                $message = 'A screenshot was captured on the employee\'s device for this '
                    . 'violation but was never stored on the server — an upload or storage '
                    . 'error, not a data-retention purge. Open Help → Troubleshooting → '
                    . 'Screenshot evidence to see which device(s) are affected.';
            } elseif ($ev->screenshot_captured) {
                // No screenshot rows survive at all → genuinely purged by data retention.
                $reason  = 'EXPIRED';
                $message = 'The screenshot for this violation is no longer available — it was removed by the data-retention policy.';
            } else {
                $reason  = 'NO_EVIDENCE';
                $message = 'No screenshot was captured for this violation.';
            }

            return response()->json(['data' => [
                'violation' => $violation, 'available' => false, 'reason' => $reason, 'message' => $message, 'evidence' => [],
            ]]);
        }

        $this->audit($request, 'EXPORT', EmployeeScreenshotLog::class, null, ['violation_id' => $ev->id]);

        return response()->json(['data' => [
            'violation' => $violation,
            'available' => true,
            'reason'    => null,
            'evidence'  => $available->map(fn ($s) => [
                'id'            => $s->id,
                'captured_at'   => $s->captured_at?->toDateTimeString(),
                'active_app'    => $s->active_app,
                'window_title'  => $s->window_title,
                'trigger_reason' => $s->trigger_reason,
                'url'           => route('screenshots.file', ['screenshot' => $s->id]),
            ])->values(),
        ]]);
    }

    /** GET /api/reports/employee/{employee}/compliance — event list for a date. */
    public function report(Request $request, Employee $employee): JsonResponse
    {
        $this->assertEmployeeVisible($request, $employee->id);
        $date = $request->query('date', now()->toDateString());

        $events = EmployeeComplianceEvent::where('employee_id', $employee->id)
            ->whereDate('started_at', $date)
            ->latest('started_at')
            ->limit(1000)
            ->get();

        return response()->json(['data' => $events]);
    }

    /** GET /api/dashboard/violations — recent violations across the tenant (manager+). */
    public function feed(Request $request): JsonResponse
    {
        $visible = $this->visibleEmployeeIds($request->user());
        $events = EmployeeComplianceEvent::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->with('employee:id,first_name,last_name,employee_code')
            ->when($request->category, fn ($q, $v) => $q->where('event_category', $v))
            ->when($request->severity, fn ($q, $v) => $q->where('severity', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', (int) $v))
            ->when($request->date, fn ($q, $v) => $q->whereDate('started_at', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('started_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('started_at', '<=', $v))
            ->latest('started_at')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json($events);
    }
}
