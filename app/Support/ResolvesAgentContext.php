<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Services\GateService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Shared helper for agent (device-token) endpoints: verifies the 'agent' ability,
 * resolves the calling Employee from the authenticated account, and (when a
 * device_uuid is supplied) the bound EmployeeDevice.
 */
trait ResolvesAgentContext
{
    /**
     * QA Phase 3 (A3): refuse tracking ingestion while the biometric gate is closed
     * (no door punch yet, or a mid-day soft-lock), so pre-punch / punched-out capture
     * can't be force-posted. Returns 423 with the same GATE_CLOSED shape the agent
     * already handles on attendance-event. No-op for non-gated companies.
     */
    protected function assertGateOpen(Employee $employee, ?EmployeeDevice $device = null): void
    {
        if (! app(GateService::class)->isOpen($employee, $device)) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code'    => 'GATE_CLOSED',
                    'message' => 'No door punch received yet. Punch IN at the entrance to start your session.',
                ],
            ], 423));
        }
    }

    protected function agentEmployee(Request $request): Employee
    {
        abort_unless($request->user()?->tokenCan('agent'), 403, 'Agent token required.');

        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_if(! $employee, 422, 'No employee is linked to this account.');

        return $employee;
    }

    protected function agentDevice(Request $request, Employee $employee): ?EmployeeDevice
    {
        $uuid = $request->input('device_uuid');
        if (! $uuid) {
            return null;
        }

        $device = EmployeeDevice::where('device_uuid', $uuid)->first();
        abort_if($device && $device->employee_id !== $employee->id, 403, 'Device is not bound to this employee.');

        // R5 EPT-08: the agent token must be the one issued to THIS device at
        // registration. Sanctum names each device token 'device:{uuid}', so a
        // token presented with a *different* device_uuid (spoofing) is rejected.
        // Guarded on the token name, so any non-device-token context is skipped.
        $token = $request->user()?->currentAccessToken();
        $tokenName = $token ? (string) $token->getAttribute('name') : '';
        abort_if(
            $device && $tokenName !== '' && $tokenName !== 'device:' . $uuid,
            403,
            'Agent token does not match this device.'
        );

        return $device;
    }
}
