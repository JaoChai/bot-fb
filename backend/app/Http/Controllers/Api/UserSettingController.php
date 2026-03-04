<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserSettingController extends Controller
{
    /**
     * Get current user's settings.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings;

        // Return safe data (masked keys)
        return response()->json([
            'data' => [
                'openrouter_configured' => $settings?->hasOpenRouterKey() ?? false,
                'openrouter_api_key_masked' => $settings?->masked_openrouter_key,
                'openrouter_model' => $settings?->openrouter_model ?? 'openai/gpt-4o-mini',
                'line_configured' => $settings?->hasLineCredentials() ?? false,
                'line_channel_secret_masked' => $settings?->masked_line_secret,
                'line_channel_access_token_masked' => $settings?->masked_line_token,
            ],
        ]);
    }

    /**
     * Update OpenRouter settings.
     */
    public function updateOpenRouter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => 'nullable|string|max:500',
            'model' => 'required|string|max:100',
        ]);

        $user = $request->user();
        $settings = $user->getOrCreateSettings();

        // Only update API key if provided (not empty)
        if (! empty($validated['api_key'])) {
            $settings->openrouter_api_key = $validated['api_key'];
        }

        $settings->openrouter_model = $validated['model'];
        $settings->save();

        return response()->json([
            'message' => 'OpenRouter settings updated successfully',
            'data' => [
                'openrouter_configured' => $settings->hasOpenRouterKey(),
                'openrouter_api_key_masked' => $settings->masked_openrouter_key,
                'openrouter_model' => $settings->openrouter_model,
            ],
        ]);
    }

    /**
     * Update LINE channel settings.
     */
    public function updateLine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel_secret' => 'nullable|string|max:100',
            'channel_access_token' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $settings = $user->getOrCreateSettings();

        // Only update if provided
        if (! empty($validated['channel_secret'])) {
            $settings->line_channel_secret = $validated['channel_secret'];
        }
        if (! empty($validated['channel_access_token'])) {
            $settings->line_channel_access_token = $validated['channel_access_token'];
        }

        $settings->save();

        return response()->json([
            'message' => 'LINE settings updated successfully',
            'data' => [
                'line_configured' => $settings->hasLineCredentials(),
                'line_channel_secret_masked' => $settings->masked_line_secret,
                'line_channel_access_token_masked' => $settings->masked_line_token,
            ],
        ]);
    }

    /**
     * Test OpenRouter API connection.
     */
    public function testOpenRouter(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings;

        if (! $settings?->hasOpenRouterKey()) {
            return response()->json([
                'success' => false,
                'message' => 'OpenRouter API key not configured',
            ], 400);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$settings->openrouter_api_key,
            ])
                ->timeout(10)
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => $settings->openrouter_model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Say "OK" if you can read this.'],
                    ],
                    'max_tokens' => 10,
                ]);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connection successful! Model: '.$settings->openrouter_model,
                ]);
            }

            $error = $response->json('error.message', 'Unknown error');

            return response()->json([
                'success' => false,
                'message' => "API error: {$error}",
            ], 400);
        } catch (\Exception $e) {
            Log::warning('OpenRouter test failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test LINE channel connection.
     */
    public function testLine(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings;

        if (! $settings?->hasLineCredentials()) {
            return response()->json([
                'success' => false,
                'message' => 'LINE credentials not configured',
            ], 400);
        }

        try {
            // Test by getting bot info
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$settings->line_channel_access_token,
            ])
                ->timeout(10)
                ->get('https://api.line.me/v2/bot/info');

            if ($response->successful()) {
                $botInfo = $response->json();

                return response()->json([
                    'success' => true,
                    'message' => 'Connected to LINE Bot: '.($botInfo['displayName'] ?? 'Unknown'),
                    'bot_name' => $botInfo['displayName'] ?? null,
                ]);
            }

            $error = $response->json('message', 'Unknown error');

            return response()->json([
                'success' => false,
                'message' => "LINE API error: {$error}",
            ], 400);
        } catch (\Exception $e) {
            Log::warning('LINE test failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear OpenRouter API key.
     */
    public function clearOpenRouter(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings;

        if ($settings) {
            $settings->openrouter_api_key = null;
            $settings->save();
        }

        return response()->json([
            'message' => 'OpenRouter API key cleared',
        ]);
    }

    /**
     * Clear LINE credentials.
     */
    public function clearLine(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings;

        if ($settings) {
            $settings->line_channel_secret = null;
            $settings->line_channel_access_token = null;
            $settings->save();
        }

        return response()->json([
            'message' => 'LINE credentials cleared',
        ]);
    }
}
