<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeComplianceEvent;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplianceController extends Controller
{
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
        ]);

        return response()->json(['ok' => true, 'compliance_event_id' => $event->id], 201);
    }

    /** GET /api/reports/employee/{employee}/compliance — event list for a date. */
    public function report(Request $request, Employee $employee): JsonResponse
    {
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
        $events = EmployeeComplianceEvent::query()
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
