<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticates a request by a SmartEPT API key (public /api/v1 surface).
 * Accepts either `Authorization: Bearer <key>` or `X-Api-Key: <key>`.
 * On success the resolved ApiKey and its company_id are attached to the request
 * so ingest/read controllers stay company-scoped. Optional scope enforcement:
 * ->middleware('api-key:ingest').
 *
 * Keys are no longer immortal. The bridge (SBB) stores its key in a config file
 * on a customer's Windows PC, so a leak used to mean unlimited access until a
 * human noticed and revoked it. Two limits now apply when they are set:
 *  - expires_at   — hard cut-off, the key stops working on its own.
 *  - allowed_ips  — an IP / CIDR allow-list; empty means "anywhere".
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $raw = $request->bearerToken() ?: $request->header('X-Api-Key');
        $raw = trim((string) $raw);

        if ($raw === '') {
            return $this->deny('Missing API key. Send it as "Authorization: Bearer <key>" or the X-Api-Key header.', 401);
        }

        $hash = hash('sha256', $raw);
        $key = ApiKey::where('key_hash', $hash)->where('active', true)->first();

        if (! $key) {
            return $this->deny('Invalid or revoked API key.', 401);
        }
        if ($key->expires_at && $key->expires_at->isPast()) {
            return $this->deny('This API key expired on ' . $key->expires_at->toDateString() . '. Issue a new one on the Integrations screen.', 401);
        }
        if (! $this->ipAllowed($key, (string) $request->ip())) {
            return $this->deny('This API key is not allowed from ' . $request->ip() . '.', 403);
        }
        if ($scope && ! $key->hasScope($scope)) {
            return $this->deny('This API key does not have the "' . $scope . '" scope.', 403);
        }

        // Throttle the write to once a minute so ingest bursts don't hammer the row.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_company_id', $key->company_id);

        return $next($request);
    }

    /** Empty / null allow-list = any IP. Entries may be plain IPs or CIDR blocks. */
    private function ipAllowed(ApiKey $key, string $ip): bool
    {
        $allowed = array_filter((array) ($key->allowed_ips ?: []));

        if (! $allowed) {
            return true;
        }

        foreach ($allowed as $entry) {
            $entry = trim((string) $entry);

            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && $this->inCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** IPv4 CIDR match. Non-IPv4 / malformed entries simply do not match. */
    private function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long((string) $subnet);
        $bits = (int) $bits;

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function deny(string $msg, int $code)
    {
        return response()->json(['error' => ['code' => 'API_KEY_' . $code, 'message' => $msg]], $code);
    }
}
