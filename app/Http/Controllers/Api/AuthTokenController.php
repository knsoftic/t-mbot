<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthTokenController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Exchange credentials for a personal access token.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->audit->log('api.login_failed', null, 'Failed API login for '.$data['email']);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is disabled.']);
        }

        $token = $user->createToken($data['device_name']);
        $this->audit->log('api.token_created', $user, "API token “{$data['device_name']}” issued via API login", [], $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role?->slug],
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->audit->log('api.token_revoked', $request->user(), 'API token revoked via API logout');

        return response()->json(['message' => 'Token revoked.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role.permissions');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->slug,
            'permissions' => $user->isSuperAdmin() ? ['*'] : $user->role?->permissions->pluck('slug') ?? [],
            'timezone' => $user->displayTimezone(),
        ]);
    }
}
