<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One-click SSO from SmartEPT Central (Ejaz 17-Jul). A cloud client clicks
 * "Open my Console" in the Central portal; Central mints a short-lived token
 * signed with the shared SSO secret and sends the browser here. We verify it and
 * hand back a normal Sanctum token — the SPA then behaves exactly as after a
 * password login. No password round-trip, no second sign-in.
 *
 * Token = base64url(json{email,tid,exp}) . hmac_sha256(base64url, secret)
 * ponytail: stateless + 120s expiry, no single-use nonce. A stolen ticket is
 * replayable inside its short window; add a used-jti table if that matters.
 */
class SsoController extends Controller
{
    /** POST /api/auth/sso  body: { token } */
    public function login(Request $request): JsonResponse
    {
        $secret = (string) config('services.sso.secret');
        if ($secret === '') {
            return response()->json(['error' => ['code' => 'SSO_DISABLED', 'message' => 'SSO is not configured.']], 503);
        }

        $token = (string) $request->input('token', '');
        $claims = $this->verify($token, $secret);
        if (! $claims) {
            return response()->json(['error' => ['code' => 'BAD_TICKET', 'message' => 'Invalid or expired SSO link.']], 401);
        }

        $user = User::where('email', $claims['email'])
            ->whereHas('company', fn ($q) => $q->where('external_tenant_id', $claims['tid']))
            ->first();

        if (! $user || $user->status === 'DISABLED') {
            return response()->json(['error' => ['code' => 'NO_USER', 'message' => 'No active console account for this client.']], 401);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $abilities = ['role:' . ($user->roleSlug() ?? 'NONE')];
        $accessToken = $user->createToken('sso-web', $abilities)->plainTextToken;

        return response()->json(['token' => $accessToken]);
    }

    /** @return array{email:string,tid:string}|null */
    private function verify(string $token, string $secret): ?array
    {
        $dot = strrpos($token, '.');
        if ($dot === false) {
            return null;
        }
        $body = substr($token, 0, $dot);
        $sig = substr($token, $dot + 1);
        $expected = hash_hmac('sha256', $body, $secret);
        if (! hash_equals($expected, $sig)) {
            return null;
        }
        $json = base64_decode(strtr($body, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $claims = json_decode($json, true);
        if (! is_array($claims) || empty($claims['email']) || empty($claims['tid']) || empty($claims['exp'])) {
            return null;
        }
        if (time() > (int) $claims['exp']) {
            return null;
        }
        return ['email' => (string) $claims['email'], 'tid' => (string) $claims['tid']];
    }
}
