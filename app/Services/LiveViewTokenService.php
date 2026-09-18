<?php

namespace App\Services;

/**
 * Mints and verifies the short-lived signed tokens LiveViewController hands to the
 * two relay legs (plan §12 — "the relay checks the token's signature and claims on
 * every connect; it does not re-derive business rules"). HMAC-SHA256, shared-secret
 * — no session/employee data ever needs to reach the relay process, only an opaque
 * signed claim it can verify on its own.
 *
 * Token shape: base64url(json payload) . base64url(hmac-sha256(payload, secret))
 * Small and dependency-free on purpose — this is not a JWT library, just the one
 * property JWT is usually reached for (a signature the other side can check).
 */
class LiveViewTokenService
{
    public function mint(int $sessionId, string $role, ?int $ttlSeconds = null): string
    {
        $payload = [
            'sid' => $sessionId,
            'role' => $role, // 'stream' (agent leg) | 'view' (admin-browser leg)
            'exp' => now()->addSeconds($ttlSeconds ?? (int) config('liveview.token_ttl_seconds'))->timestamp,
        ];

        $body = $this->b64url(json_encode($payload));
        $sig = $this->b64url(hash_hmac('sha256', $body, $this->secret(), true));

        return $body . '.' . $sig;
    }

    /** Returns the decoded payload if the token is validly signed, unexpired and the right role — else null. */
    public function verify(string $token, string $expectRole): ?array
    {
        if (! str_contains($token, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        $expected = $this->b64url(hash_hmac('sha256', $body, $this->secret(), true));
        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $payload = json_decode($this->b64urlDecode($body), true);
        if (! is_array($payload) || ($payload['role'] ?? null) !== $expectRole) {
            return null;
        }
        if (! isset($payload['exp']) || $payload['exp'] < now()->timestamp) {
            return null;
        }

        return $payload;
    }

    private function secret(): string
    {
        $secret = (string) config('liveview.relay_secret');
        abort_if($secret === '', 500, 'LIVEVIEW_RELAY_SECRET is not configured.');

        return $secret;
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $b64url): string
    {
        return (string) base64_decode(strtr($b64url, '-_', '+/') . str_repeat('=', (4 - strlen($b64url) % 4) % 4));
    }
}
