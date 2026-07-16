<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeMonitoringConsent;
use App\Services\PolicyResolver;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsentController extends Controller
{
    use ResolvesAgentContext;

    /**
     * POST /api/agent/consent
     * Records the employee's acknowledgement of the current monitoring policy.
     */
    public function store(Request $request, PolicyResolver $resolver): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'       => ['nullable', 'string'],
            'consent_text_hash' => ['nullable', 'string', 'max:191'],
            'acknowledged'      => ['required', 'boolean'],
        ]);

        $bundle = $resolver->bundleForEmployee($employee);

        $consent = EmployeeMonitoringConsent::create([
            'company_id'           => $employee->company_id,
            'employee_id'          => $employee->id,
            'monitoring_policy_id' => $employee->monitoring_policy_id,
            'policy_version'       => (int) $bundle['policy_version'],
            'consent_text_hash'    => $data['consent_text_hash'] ?? null,
            'acknowledged'         => (bool) $data['acknowledged'],
            'acknowledged_at'      => $data['acknowledged'] ? now() : null,
            'device_uuid'          => $data['device_uuid'] ?? null,
            'ip_address'           => $request->ip(),
        ]);

        return response()->json([
            'ok'             => true,
            'consent_id'     => $consent->id,
            'policy_version' => $consent->policy_version,
        ], 201);
    }

    /** GET /api/agent/consent/status — has the employee consented to the current version? */
    public function status(Request $request, PolicyResolver $resolver): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        $bundle = $resolver->bundleForEmployee($employee);

        $ok = EmployeeMonitoringConsent::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('acknowledged', true)
            ->where('policy_version', '>=', (int) $bundle['policy_version'])
            ->exists();

        return response()->json([
            'consent_required'  => (bool) $bundle['consent_required'],
            'has_consented'     => $ok,
            'policy_version'    => $bundle['policy_version'],
        ]);
    }
}
