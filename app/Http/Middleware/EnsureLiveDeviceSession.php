<?php

namespace App\Http\Middleware;

use App\Models\EmployeeDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse tracking data from a device whose session the server has already ended.
 *
 * 26-Aug-2026 (Ejaz): "the dashboard shows the agent signed out and not active … active agents
 * shows 0, but still the violations are captured from the agent and saved in the violations
 * tab. This is a serious bug."
 *
 * Exactly right, and it was a hole in the sign-out design rather than one bug. Ending a session
 * relied SOLELY on deleting the device's Sanctum token to make the agent 401 and stop. Any path
 * that closed a session without deleting that token left an agent holding a live credential —
 * still tracking, still uploading — while every screen said the employee was signed out. The
 * nightly stale-session close did precisely that, and the post-shift close skipped revocation
 * for any device whose session_status had already moved off ACTIVE.
 *
 * Revocation is now reliable at those call sites, but relying on one mechanism to stop data
 * being written is the actual mistake. The device row is the authority on whether a session is
 * live, so it is checked here, on the ingestion routes themselves. A token that outlives its
 * session — leaked, cached, replayed, or missed by a future code path — buys nothing.
 *
 * 401 (not 403) is deliberate: the agent's own heartbeat handler treats 401 as
 * "session ended elsewhere" and returns the window to the sign-in screen.
 *
 * Deliberately NOT applied to register-device (that is how you sign back in), heartbeat,
 * policy, consent or gate-status — the agent must still be able to find out that it has been
 * signed out.
 */
class EnsureLiveDeviceSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // The device is taken from the TOKEN, not the request body: the token is named
        // "device:<uuid>" at registration, so this identifies the caller on every route
        // regardless of whether that route happens to carry device_uuid in its payload
        // (several do not, and a multipart upload carries it differently again). It is also
        // the identifier the caller cannot choose.
        $name = (string) ($request->user()?->currentAccessToken()?->name ?? '');
        if (! str_starts_with($name, 'device:')) {
            return $next($request);   // not a device-scoped token — not ours to judge
        }
        $uuid = substr($name, 7);

        $device = EmployeeDevice::withoutGlobalScopes()
            ->where('device_uuid', $uuid)
            ->first(['id', 'session_status', 'unbound_at']);

        // An unknown device_uuid is left to the route (it 404s or registers). Only a device we
        // KNOW is signed out is refused, so a mistake here can never block a live agent.
        if ($device && ($device->unbound_at || $device->session_status !== 'ACTIVE')) {
            return response()->json([
                'error' => [
                    'code' => 'SESSION_ENDED',
                    'message' => 'This device session has ended. Please sign in again.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
