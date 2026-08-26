<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnforcementMachine;
use App\Models\EnrollmentToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enrolment for the Windows enforcement service.
 *
 * The service needs an identity of its own so it can sync at boot with nobody
 * signed in (decision 9). The installer is handed a one-time secret; this
 * endpoint exchanges it for two long-lived values:
 *
 *   device_token  a Sanctum token with the `enforcer` ability. Authenticates
 *                 this machine to us.
 *   hmac_key      keys the endpoint's local policy store. Proves a stored
 *                 policy came from us and was not edited on disk.
 *
 * They are separate on purpose: a leaked bearer token must not also be a way to
 * forge a policy the endpoint would accept.
 *
 * This route is UNAUTHENTICATED by necessity — the machine has no credential
 * yet, which is what it is here to get. Everything that protects it is in the
 * token: hashed at rest, time-bounded, use-bounded, revocable, and scoped to
 * one company.
 */
class EnforcerEnrollmentController extends Controller
{
    /**
     * POST /api/enforcer/enroll — unauthenticated, one-time-token gated.
     */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token'  => ['required', 'string', 'max:128'],
            'machine_id'        => ['required', 'string', 'max:128'],
            'hostname'          => ['nullable', 'string', 'max:191'],
            'os_version'        => ['nullable', 'string', 'max:120'],
            'edition'           => ['nullable', 'string', 'max:60'],
            'device_uuid'       => ['nullable', 'string', 'max:64'],
            'enforcement_level' => ['nullable', 'in:' . implode(',', EnforcementMachine::LEVELS)],
        ]);

        $token = EnrollmentToken::redeemable($data['enrollment_token']);
        if (! $token) {
            // Deliberately one message for every failure mode. Telling an
            // unauthenticated caller whether a token exists, has expired or is
            // spent is free reconnaissance.
            Log::warning('Enforcer enrolment refused', [
                'machine_id' => $data['machine_id'],
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'ok' => false,
                'reason' => 'invalid_enrollment_token',
                'message' => 'That enrolment token is not valid. Generate a new one from the console.',
            ], 403);
        }

        // The integrity key is generated here, handed over once, and NOT stored.
        // The server never reads the endpoint's policy store, so it has no
        // reason to hold the key that protects it.
        $hmacKey = bin2hex(random_bytes(32));

        $result = DB::transaction(function () use ($data, $token, $hmacKey) {
            // Re-enrolment of a machine we already know is an update, not a
            // duplicate. A reinstalled PC keeps its identity in the console
            // rather than appearing twice.
            $machine = EnforcementMachine::withoutGlobalScopes()->firstOrNew([
                'company_id' => $token->company_id,
                'machine_id' => $data['machine_id'],
            ]);

            $machine->forceFill([
                'company_id'        => $token->company_id,
                'machine_id'        => $data['machine_id'],
                'hostname'          => $data['hostname'] ?? $machine->hostname,
                'os_version'        => $data['os_version'] ?? $machine->os_version,
                'edition'           => $data['edition'] ?? $machine->edition,
                'device_uuid'       => $data['device_uuid'] ?? $machine->device_uuid,
                'enforcement_level' => $data['enforcement_level'] ?? EnforcementMachine::LEVEL_NONE,
                // A machine that has just enrolled has not proven it can
                // enforce anything yet, whatever it claims it is capable of.
                'enforcement_health' => 'UNKNOWN',
                'integrity_key_fp'  => substr(hash('sha256', $hmacKey), 0, 16),
                'enrolled_at'       => now(),
                'last_seen_at'      => now(),
                'revoked_at'        => null,
            ])->save();

            // Re-enrolling invalidates the old credential. A stolen token from
            // a decommissioned machine must stop working the moment that
            // machine is rebuilt.
            $machine->tokens()->delete();
            $plain = $machine->createToken('enforcer', ['enforcer'])->plainTextToken;

            $token->increment('uses');

            return [$machine, $plain];
        });

        [$machine, $deviceToken] = $result;

        return response()->json([
            'ok'           => true,
            'device_token' => $deviceToken,
            'hmac_key'     => $hmacKey,
            'tenant_id'    => (string) $machine->company_id,
            'device_uuid'  => $machine->device_uuid,
        ], 201);
    }

    /**
     * POST /api/enforcer/enrollment-tokens — console side, admin only.
     *
     * The plaintext secret is in this response and nowhere else, ever.
     */
    public function mint(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'label'     => ['nullable', 'string', 'max:120'],
            'ttl_hours' => ['nullable', 'integer', 'min:1', 'max:' . EnrollmentToken::MAX_TTL_HOURS],
            'max_uses'  => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        [$token, $secret] = EnrollmentToken::mint(
            (int) $request->user()->company_id,
            $request->user()->id,
            (string) ($data['label'] ?? ''),
            (int) ($data['ttl_hours'] ?? EnrollmentToken::DEFAULT_TTL_HOURS),
            (int) ($data['max_uses'] ?? 1),
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'id'         => $token->id,
                'label'      => $token->label,
                'expires_at' => $token->expires_at->toIso8601String(),
                'max_uses'   => $token->max_uses,
                // Shown once. Say so, so nobody closes the dialog expecting to
                // find it again later.
                'secret'     => $secret,
                'note'       => 'Copy this now — it cannot be shown again.',
            ],
        ], 201);
    }

    /**
     * POST /api/agent/enforcer-handoff — the agent, for the PC it runs on.
     *
     * Returns a short-lived, single-use enrolment token so the agent can hand the
     * enforcement service on the SAME machine everything it needs to join.
     *
     * Why this exists.
     *
     * The installer used to carry a server address and a token, both decided at
     * BUILD time. That is the wrong place to decide either. A build machine's
     * address is not the client's; an installer built against the wrong one
     * produces PCs that report activity and never block, and nothing about them
     * looks wrong until somebody asks why a rule did nothing. It also meant a
     * separate installer per client, and a token sitting inside an .exe.
     *
     * The agent already knows the address — the employee signed in through it —
     * and already holds a credential this server trusts. So it asks here and
     * passes both to the service locally. The installer carries nothing at all,
     * which means ONE build works at every client, on any address, IP or hostname.
     *
     * Deliberately narrow: single use, one hour, and it can only ever enrol a
     * machine into the company the caller already belongs to.
     */
    public function handoff(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->company_id, 401);

        // Single use and one hour. This token travels from the agent to a file
        // on the same PC and is spent within seconds; anything longer is a
        // credential lying around for no reason.
        [$token, $secret] = EnrollmentToken::mint(
            (int) $user->company_id,
            $user->id,
            'Agent handoff — ' . ($request->input('hostname') ?: 'unnamed PC'),
            1,
            1,
        );

        Log::info('Enforcer handoff token issued to an agent', [
            'company_id' => $user->company_id,
            'user_id'    => $user->id,
            'token_id'   => $token->id,
            'hostname'   => $request->input('hostname'),
        ]);

        return response()->json([
            'ok' => true,
            'data' => [
                // The address this request ARRIVED on.
                //
                // Not config('app.url') and not anything stored: those are what
                // the server calls itself, which is how http://smartept.test and
                // http://localhost end up on an employee's PC. This value is
                // proven reachable from that machine, because a request from it
                // just came in over it. Whatever the client uses — an IP, a
                // hostname, HTTPS behind a reverse proxy — is what goes back.
                'server_url'  => rtrim($request->getSchemeAndHttpHost(), '/'),
                'enrol_token' => $secret,
                'expires_at'  => $token->expires_at->toIso8601String(),
            ],
        ]);
    }

    /** GET /api/enforcer/enrollment-tokens — never returns a secret. */
    public function tokens(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $rows = EnrollmentToken::withoutGlobalScopes()
            ->where('company_id', (int) $request->user()->company_id)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'label', 'expires_at', 'max_uses', 'uses', 'revoked_at', 'created_at'])
            ->map(fn (EnrollmentToken $t) => [
                'id'         => $t->id,
                'label'      => $t->label,
                'expires_at' => $t->expires_at?->toIso8601String(),
                'max_uses'   => $t->max_uses,
                'uses'       => $t->uses,
                'usable'     => $t->usable(),
                'reason'     => $t->unusableReason(),
            ]);

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /** POST /api/enforcer/enrollment-tokens/{id}/revoke */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $token = EnrollmentToken::withoutGlobalScopes()
            ->where('company_id', (int) $request->user()->company_id)
            ->findOrFail($id);

        $token->forceFill(['revoked_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** GET /api/enforcer/machines — what is enrolled, and what it is achieving. */
    public function machines(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $rows = EnforcementMachine::withoutGlobalScopes()
            ->where('company_id', (int) $request->user()->company_id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (EnforcementMachine $m) => [
                'id'                     => $m->id,
                'machine_id'             => $m->machine_id,
                'hostname'               => $m->hostname,
                'os_version'             => $m->os_version,
                'enforcement_level'      => $m->enforcement_level,
                'enforcement_health'     => $m->enforcement_health,
                'applied_policy_version' => $m->applied_policy_version,
                'enforcer_version'       => $m->enforcer_version,
                'windows_sid'            => $m->windows_sid,
                'last_seen_at'           => $m->last_seen_at?->toIso8601String(),
                // The distinction the console has to be able to draw.
                'protected'              => $m->isProtected(),
            ]);

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /** POST /api/enforcer/machines/{id}/revoke — cut one endpoint off. */
    public function revokeMachine(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $machine = EnforcementMachine::withoutGlobalScopes()
            ->where('company_id', (int) $request->user()->company_id)
            ->findOrFail($id);

        $machine->tokens()->delete();
        $machine->forceFill(['revoked_at' => now()])->save();

        // NOTE: this stops the endpoint SYNCING. It does not remove the policy
        // already on that machine — a revoked credential must not be a way to
        // silently disarm a PC. Use the kill switch for that.
        return response()->json([
            'ok' => true,
            'message' => 'This endpoint can no longer sync. Any policy already applied to it stays in force — '
                . 'use Switch enforcement off if you need to remove it.',
        ]);
    }

    /**
     * The five endpoints on this controller are administrator-only.
     *
     * This compared $user->role against a list of strings. `role` is the Role
     * MODEL — a belongsTo relation, not a slug — so the comparison was never
     * true for anybody and every one of these endpoints answered 403 to its own
     * administrators. Minting an enrolment token was impossible, which means no
     * new machine could be enrolled at all.
     *
     * The same defect was fixed in EnforcementController by moving to the
     * `role:` middleware; this copy was missed, because a private method is
     * invisible to a search for the route that broke.
     *
     * roleSlug() is what the rest of the application already uses.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless(
            in_array($user->roleSlug(), ['SUPER_ADMIN', 'COMPANY_ADMIN'], true),
            403,
            'Only an administrator can manage enforcement endpoints.'
        );
    }
}
