<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Models\EmployeeMonitoringConsent;
use App\Services\PolicyResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for tracking-ingestion routes. When the effective monitoring policy sets
 * consent_required, the employee must have an acknowledged consent for that policy
 * version before any tracking data is accepted. Enforces the SmartEPT rule that no
 * sensitive capture happens without recorded consent where policy requires it.
 */
class EnsureConsent
{
    public function __construct(private PolicyResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->tokenCan('agent')) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Agent token required.'],
            ], 403);
        }

        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee) {
            return response()->json([
                'error' => ['code' => 'NO_EMPLOYEE', 'message' => 'No employee linked to this account.'],
            ], 422);
        }

        $bundle = $this->resolver->bundleForEmployee($employee);

        if (! empty($bundle['consent_required'])) {
            $ok = EmployeeMonitoringConsent::withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->where('acknowledged', true)
                ->where('policy_version', '>=', (int) $bundle['policy_version'])
                ->exists();

            if (! $ok) {
                return response()->json([
                    'error' => [
                        'code'            => 'CONSENT_REQUIRED',
                        'message'         => 'Monitoring consent is required before tracking can start.',
                        'policy_version'  => $bundle['policy_version'],
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
