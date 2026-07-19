<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Services\PolicyResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of tracking_mode (Ejaz 19-Jul), sitting in front of the
 * agent tracking-ingestion routes. This is defence in depth: even an old agent that
 * doesn't know about tracking modes cannot write logs for someone who is EXCLUDED or
 * PRESENCE_ONLY — the request is dropped here before it reaches a controller.
 *
 *   EXCLUDED      → drop every tracking POST (204). A liveness heartbeat still runs
 *                   on the bootstrap routes, so the PC shows online, not monitored.
 *   PRESENCE_ONLY → allow attendance + manual break only; drop the rest (204).
 *   FULL          → pass through.
 *
 * 204 (not 4xx) so the agent's sync queue treats it as delivered and drops the item
 * instead of retrying forever.
 */
class EnforceTrackingMode
{
    /** Streams that survive PRESENCE_ONLY (time & attendance, not surveillance). */
    private const PRESENCE_ALLOWED = ['attendance-event', 'break-event'];

    public function __construct(private PolicyResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->tokenCan('agent')) {
            // Let the normal auth/consent layers produce the real error.
            return $next($request);
        }

        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee) {
            return $next($request);
        }

        $device = null;
        if ($request->filled('device_uuid')) {
            $device = EmployeeDevice::where('device_uuid', $request->input('device_uuid'))->first();
        }

        $mode = $this->resolver->effectiveTrackingMode($employee, $device);

        if ($mode === 'EXCLUDED') {
            return response()->noContent(); // 204 — nothing is stored for this person
        }

        if ($mode === 'PRESENCE_ONLY') {
            $action = Str::afterLast($request->path(), '/'); // e.g. api/agent/app-usage -> app-usage
            if (! in_array($action, self::PRESENCE_ALLOWED, true)) {
                return response()->noContent();
            }
        }

        return $next($request);
    }
}
