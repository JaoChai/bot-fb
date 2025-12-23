<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'subscription_plan' => 'free',
            'timezone' => $request->timezone ?? 'Asia/Bangkok',
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Login user and create token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Optionally revoke old tokens
        if ($request->boolean('revoke_previous')) {
            $user->tokens()->delete();
        }

        $token = $user->createToken(
            $request->device_name ?? 'auth-token',
            ['*'], // abilities
            now()->addDays(30) // expiration
        )->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user->load(['bots' => fn($q) => $q->withCount('conversations')]),
        ]);
    }

    /**
     * Logout user (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Logout from all devices (revoke all tokens).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices',
        ]);
    }

    /**
     * Refresh token (create new, revoke old).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Create new token
        $token = $user->createToken(
            'auth-token',
            ['*'],
            now()->addDays(30)
        )->plainTextToken;

        return response()->json([
            'message' => 'Token refreshed',
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * List all active tokens for the user.
     */
    public function tokens(Request $request): JsonResponse
    {
        return response()->json([
            'tokens' => $request->user()->tokens()->get(['id', 'name', 'last_used_at', 'created_at']),
        ]);
    }

    /**
     * Revoke a specific token.
     */
    public function revokeToken(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'Token not found',
            ], 404);
        }

        return response()->json([
            'message' => 'Token revoked',
        ]);
    }
}
