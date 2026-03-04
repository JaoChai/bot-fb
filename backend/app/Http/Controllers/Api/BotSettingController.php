<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotSettingController extends Controller
{
    /**
     * Get bot settings.
     *
     * @OA\Get(
     *     path="/api/bots/{bot}/settings",
     *     summary="Get bot settings",
     *     description="Returns all configuration settings for a bot. Creates default settings if none exist.",
     *     operationId="getBotSettings",
     *     tags={"Bot Settings"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/BotSettings")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found")
     * )
     */
    public function show(Request $request, Bot $bot): JsonResponse
    {
        try {
            $this->authorize('view', $bot);

            // Get or create settings for this bot
            $settings = $bot->settings ?? $this->createDefaultSettings($bot);

            return response()->json([
                'data' => $settings->toArray(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('BotSettingController::show error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Update bot settings.
     *
     * @OA\Put(
     *     path="/api/bots/{bot}/settings",
     *     summary="Update bot settings",
     *     description="Updates configuration settings for a bot including usage limits, HITL, response hours, and more.",
     *     operationId="updateBotSettings",
     *     tags={"Bot Settings"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="daily_message_limit", type="integer", minimum=0, maximum=100000, example=1000),
     *             @OA\Property(property="per_user_limit", type="integer", minimum=0, maximum=10000, example=100),
     *             @OA\Property(property="rate_limit_per_minute", type="integer", minimum=1, maximum=1000, example=20),
     *             @OA\Property(property="max_tokens_per_response", type="integer", minimum=100, maximum=32000, example=2000),
     *             @OA\Property(property="hitl_enabled", type="boolean", description="Enable Human-in-the-Loop"),
     *             @OA\Property(property="hitl_triggers", type="array", @OA\Items(type="string"), description="Keywords to trigger HITL"),
     *             @OA\Property(property="response_hours_enabled", type="boolean"),
     *             @OA\Property(property="response_hours", type="object", description="Business hours per day"),
     *             @OA\Property(property="response_hours_timezone", type="string", example="Asia/Bangkok"),
     *             @OA\Property(property="offline_message", type="string", maxLength=1000),
     *             @OA\Property(property="welcome_message", type="string", maxLength=2000),
     *             @OA\Property(property="fallback_message", type="string", maxLength=1000),
     *             @OA\Property(property="typing_indicator", type="boolean"),
     *             @OA\Property(property="typing_delay_ms", type="integer", minimum=0, maximum=5000),
     *             @OA\Property(property="content_filter_enabled", type="boolean"),
     *             @OA\Property(property="blocked_keywords", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="analytics_enabled", type="boolean"),
     *             @OA\Property(property="save_conversations", type="boolean"),
     *             @OA\Property(property="language", type="string", enum={"th", "en", "zh", "ja", "ko"}),
     *             @OA\Property(property="response_style", type="string", enum={"professional", "casual", "friendly", "formal"}),
     *             @OA\Property(property="multiple_bubbles_enabled", type="boolean"),
     *             @OA\Property(property="smart_aggregation_enabled", type="boolean"),
     *             @OA\Property(property="auto_assignment_enabled", type="boolean"),
     *             @OA\Property(property="auto_assignment_mode", type="string", enum={"round_robin", "load_balanced"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Settings updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Settings updated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/BotSettings")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, Bot $bot): JsonResponse
    {
        try {
            $this->authorize('update', $bot);

            $validated = $request->validate([
                // Usage limits
                'daily_message_limit' => 'integer|min:0|max:100000',
                'per_user_limit' => 'integer|min:0|max:10000',
                'rate_limit_per_minute' => 'integer|min:1|max:1000',
                'max_tokens_per_response' => 'integer|min:100|max:32000',

                // HITL settings
                'hitl_enabled' => 'boolean',
                'hitl_triggers' => 'nullable|array',
                'hitl_triggers.*' => 'string|max:100',

                // Response hours (multiple slots per day)
                // Format: { "mon": [{"start":"09:00","end":"18:00"}], ... }
                'response_hours_enabled' => 'boolean',
                'response_hours' => 'nullable|array',
                'response_hours.*' => 'nullable|array',
                'response_hours.*.*.start' => 'date_format:H:i',
                'response_hours.*.*.end' => 'date_format:H:i',
                'response_hours_timezone' => 'nullable|string|timezone',
                'offline_message' => 'nullable|string|max:1000',

                // Auto-responses
                'welcome_message' => 'nullable|string|max:2000',
                'fallback_message' => 'nullable|string|max:1000',
                'rate_limit_bot_message' => 'nullable|string|max:500',
                'rate_limit_user_message' => 'nullable|string|max:500',
                'typing_indicator' => 'boolean',
                'typing_delay_ms' => 'integer|min:0|max:5000',

                // Content moderation
                'content_filter_enabled' => 'boolean',
                'blocked_keywords' => 'nullable|array',
                'blocked_keywords.*' => 'string|max:100',

                // Analytics
                'analytics_enabled' => 'boolean',
                'save_conversations' => 'boolean',

                // Language and style
                'language' => 'string|in:th,en,zh,ja,ko',
                'response_style' => 'string|in:professional,casual,friendly,formal',

                // Conversation management
                'auto_archive_days' => 'nullable|integer|min:1|max:365',

                // Multiple bubbles settings
                'multiple_bubbles_enabled' => 'boolean',
                'multiple_bubbles_min' => 'integer|min:1|max:5',
                'multiple_bubbles_max' => 'integer|min:1|max:5|gte:multiple_bubbles_min',
                'multiple_bubbles_delimiter' => 'string|max:10',
                'wait_multiple_bubbles_enabled' => 'boolean',
                'wait_multiple_bubbles_ms' => 'integer|min:500|max:20000',

                // Smart aggregation settings
                'smart_aggregation_enabled' => 'boolean',
                'smart_min_wait_ms' => 'sometimes|integer|min:300|max:3000',
                'smart_max_wait_ms' => ['sometimes', 'integer', 'min:1000', 'max:10000'],
                'smart_early_trigger_enabled' => 'boolean',
                'smart_per_user_learning_enabled' => 'boolean',

                // Reply sticker settings
                'reply_sticker_enabled' => 'boolean',
                'reply_sticker_message' => 'nullable|string|max:500',
                'reply_sticker_mode' => 'string|in:static,ai',
                'reply_sticker_ai_prompt' => 'nullable|string|max:1000',

                // Auto-assignment settings
                'auto_assignment_enabled' => 'boolean',
                'auto_assignment_mode' => 'string|in:round_robin,load_balanced',
            ]);

            // Validate smart_max_wait_ms >= smart_min_wait_ms
            if (isset($validated['smart_max_wait_ms'], $validated['smart_min_wait_ms'])) {
                if ($validated['smart_max_wait_ms'] < $validated['smart_min_wait_ms']) {
                    return response()->json([
                        'message' => 'The smart max wait must be greater than or equal to smart min wait.',
                        'errors' => [
                            'smart_max_wait_ms' => ['The smart max wait must be greater than or equal to smart min wait.'],
                        ],
                    ], 422);
                }
            }

            // Get or create settings
            $settings = $bot->settings ?? $this->createDefaultSettings($bot);

            // Log for debugging
            \Log::debug('BotSettingController::update - updating settings', [
                'bot_id' => $bot->id,
                'smart_fields' => array_filter($validated, fn ($k) => str_starts_with($k, 'smart_'), ARRAY_FILTER_USE_KEY),
            ]);

            // Update BotSetting
            $settings->update($validated);

            return response()->json([
                'message' => 'Settings updated successfully',
                'data' => $settings->fresh(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('BotSettingController::update error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Create default settings for a bot.
     */
    private function createDefaultSettings(Bot $bot): BotSetting
    {
        return BotSetting::create([
            'bot_id' => $bot->id,
            'daily_message_limit' => 1000,
            'per_user_limit' => 100,
            'rate_limit_per_minute' => 20,
            'max_tokens_per_response' => 2000,
            'hitl_enabled' => false,
            'response_hours_enabled' => false,
            'typing_indicator' => true,
            'typing_delay_ms' => 1000,
            'content_filter_enabled' => true,
            'analytics_enabled' => true,
            'save_conversations' => true,
            'language' => 'th',
            'response_style' => 'professional',
            // Note: multiple_bubbles and auto_assignment fields use database defaults
        ]);
    }
}
