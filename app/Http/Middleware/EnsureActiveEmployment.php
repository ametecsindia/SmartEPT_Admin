<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;

/**
 * R2-3 offboarding: a RELIEVED employee's agent must stop syncing even if a
 * stray token survives (tokens are revoked on relieve — this is the backstop).
 * Admin on-behalf calls pass through: the check binds to the calling account's
 * own linked employee record.
 */
class EnsureActiveEmployment
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $employee = Employee::where('user_id', $user->id)->first();

            if ($employee && $employee->employment_status === 'RELIEVED') {
                return response()->json([
                    'error' => [
                        'code' => 'EMPLOYMENT_INACTIVE',
                        'message' => 'This employee has been relieved — the monitoring agent is disabled for this account.',
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
