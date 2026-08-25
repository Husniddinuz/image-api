<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Stateless bearer-token auth via Sanctum personal access tokens: no server
 * side session to replicate, so app servers stay interchangeable behind a load
 * balancer.
 */
class AuthController extends Controller
{
    /**
     * The hash is always verified, against this throwaway hash when the account
     * does not exist, so response timing is identical either way and the login
     * endpoint cannot be used to enumerate registered e-mail addresses.
     */
    private const DUMMY_HASH = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->only('name', 'email', 'password'));

        return $this->tokenResponse($user, $request->string('device_name', 'api')->toString(), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        $passwordMatches = Hash::check(
            $request->string('password')->toString(),
            $user?->password ?? self::DUMMY_HASH,
        );

        if (! $user || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return $this->tokenResponse($user, $request->string('device_name', 'api')->toString());
    }

    /** Revokes only the token that made this call, not every session. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Token revoked.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->only('id', 'name', 'email')]);
    }

    private function tokenResponse(User $user, string $device, int $status = 200): JsonResponse
    {
        $token = $user->createToken($device !== '' ? $device : 'api', ['*']);

        return response()->json([
            'data' => [
                'user' => $user->only('id', 'name', 'email'),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ], $status);
    }
}
