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
     */
    public function show(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        // Get or create settings for this bot
        $settings = $bot->settings ?? $this->createDefaultSettings($bot);

        return response()->json([
            'data' => $settings,
        ]);
    }

    /**
     * Update bot settings.
     */
    public function update(Request $request, Bot $bot): JsonResponse
    {
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

            // Response hours
            'response_hours_enabled' => 'boolean',
            'response_hours' => 'nullable|array',
            'response_hours.*.start' => 'required_with:response_hours|date_format:H:i',
            'response_hours.*.end' => 'required_with:response_hours|date_format:H:i',
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
            'wait_multiple_bubbles_ms' => 'integer|min:500|max:5000',
        ]);

        // Get or create settings
        $settings = $bot->settings ?? $this->createDefaultSettings($bot);

        // Update settings
        $settings->update($validated);

        return response()->json([
            'message' => 'Settings updated successfully',
            'data' => $settings->fresh(),
        ]);
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
            // Multiple bubbles defaults
            'multiple_bubbles_enabled' => false,
            'multiple_bubbles_min' => 1,
            'multiple_bubbles_max' => 3,
            'multiple_bubbles_delimiter' => '|||',
            'wait_multiple_bubbles_enabled' => false,
            'wait_multiple_bubbles_ms' => 1500,
        ]);
    }
}
