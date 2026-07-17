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

    private function deny(string $msg, int $code)
    {
        return response()->json(['error' => ['code' => 'API_KEY_' . $code, 'message' => $msg]], $code);
    }
}
