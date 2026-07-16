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
     * Verifies the current password, clears the must_change_password flag set
     * by admin provisioning/reset, and revokes every OTHER token so a stolen
     * old session dies while the device performing the change stays signed in.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
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

        $this->audit($request, 'CHANGE_PASSWORD', User::class, $user->id);

        return response()->json(['message' => 'Password changed.']);
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
            'role'                 => $user->roleSlug(),
            'role_name'            => $user->role?->name,
            'permissions'          => $user->permissionSlugs(),
            // Lets UIs force the change-password screen after a temp-password login.
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}
