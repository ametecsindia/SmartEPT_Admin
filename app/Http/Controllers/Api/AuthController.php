<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** POST /api/auth/login */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->status === 'DISABLED') {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_DISABLED', 'message' => 'This account is disabled.'],
            ], 403);
        }

        // Hard cut-off: a suspended tenant (set from Central) cannot sign in.
        if ($user->company_id && optional($user->company)->status === 'SUSPENDED') {
            return response()->json([
                'error' => ['code' => 'COMPANY_SUSPENDED',
                    'message' => 'Your organisation\'s access has been suspended. Please contact Ametecs.'],
            ], 403);
        }

        // Employee Self-Service: an EMPLOYEE-role login must be tied to an employee record,
        // else scoped queries have nothing to return. Deny with a clear, safe message.
        // Only affects the EMPLOYEE role — Admin/Manager/HR/etc. are never checked here.
        if ($user->roleSlug() === 'EMPLOYEE' && ! $user->employee()->exists()) {
            return response()->json([
                'error' => ['code' => 'EMPLOYEE_NOT_LINKED',
                    'message' => 'Your employee account is not properly linked. Please contact your administrator.'],
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit($request, 'LOGIN', User::class, $user->id, null, $user);

        // Token abilities are derived from the role slug so tooling can inspect scope.
        $abilities = ['role:' . ($user->roleSlug() ?? 'NONE')];
        $token = $user->createToken('admin-web', $abilities)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * POST /api/auth/sso — one-click sign-in for a cloud tenant (EPT-27).
     * Trades a SmartEPT Central-signed ticket (email.tid.exp, HMAC-signed with
     * the shared SSO secret) for a normal Sanctum token — no password typed.
     */
    public function sso(Request $request): JsonResponse
    {
        $secret = (string) config('services.sso.secret');
        abort_if($secret === '', 503, 'Single sign-on is not configured on this server.');

        $ticket = (string) $request->input('ticket', '');
        [$body, $sig] = array_pad(explode('.', $ticket, 2), 2, '');
        abort_if($body === '' || $sig === '', 401, 'Malformed sign-in ticket.');
        abort_unless(hash_equals(hash_hmac('sha256', $body, $secret), $sig), 401, 'Invalid sign-in ticket.');

        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        abort_unless(is_array($payload) && ! empty($payload['email']) && ! empty($payload['exp']), 401, 'Malformed sign-in ticket.');
        abort_if((int) $payload['exp'] < time(), 401, 'This sign-in link has expired — open your console from the client portal again.');

        $user = User::where('email', $payload['email'])->first();
        abort_unless($user, 401, 'No matching account for this sign-in link.');
        if ($user->status === 'DISABLED') {
            return response()->json(['error' => ['code' => 'ACCOUNT_DISABLED', 'message' => 'This account is disabled.']], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit($request, 'SSO_LOGIN', User::class, $user->id, null, $user);

        $abilities = ['role:' . ($user->roleSlug() ?? 'NONE')];
        $token = $user->createToken('sso-web', $abilities)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    /** GET /api/auth/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(null, 204);
    }

    /** POST /api/auth/refresh — rotate the current token. */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $abilities = ['role:' . ($user->roleSlug() ?? 'NONE')];
        $request->user()->currentAccessToken()->delete();
        $token = $user->createToken('admin-web', $abilities)->plainTextToken;

        return response()->json(['token' => $token]);
    }

    /**
     * POST /api/auth/change-password — self-service (any authenticated role).
     *
     * Two flows share this endpoint:
     *  - Voluntary change: the caller must supply and verify their CURRENT password.
     *  - First-time / admin-reset set: a user still flagged must_change_password is
     *    already authenticated (they just signed in with the temp password) and only
     *    needs to choose their own. The current password is NOT required — the forced
     *    "Set a new password" screen shows only New + Confirm, and requiring the temp
     *    again is both redundant and the reason the old screen could not be completed.
     *
     * Either way it clears must_change_password and revokes every OTHER token, so a
     * stolen old session dies while the device performing the change stays signed in
     * (no second login).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $forcedSet = (bool) $user->must_change_password;

        $data = $request->validate([
            // Optional (and unused) on the forced first-time set; still required + verified
            // for a voluntary change so an idle session cannot silently reset the password.
            'current_password' => [$forcedSet ? 'nullable' : 'required', 'string'],
            'new_password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! $forcedSet && ! Hash::check($data['current_password'] ?? '', $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password'             => $data['new_password'], // hashed by the model cast
            'must_change_password' => false,
        ])->save();

        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->id)
            ->delete();

        $this->audit($request, $forcedSet ? 'SET_PASSWORD' : 'CHANGE_PASSWORD', User::class, $user->id);

        return response()->json([
            'message' => 'Password changed.',
            // Lets the client drop the forced screen and continue without re-login.
            'user'    => $this->userPayload($user->fresh()),
        ]);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('role', 'company');

        return [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'company_id'           => $user->company_id,
            'company'              => $user->company?->name,
            'attendance_mode'      => $user->company?->attendance_mode ?? 'BIOMETRIC',
            'role'                 => $user->roleSlug(),
            'base_role'            => $user->role?->base_slug,
            'employee_id'          => $user->employee?->id,
            'role_name'            => $user->role?->name,
            'permissions'          => $user->permissionSlugs(),
            // Lets UIs force the change-password screen after a temp-password login.
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}
