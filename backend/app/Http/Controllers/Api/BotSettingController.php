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
        try {
            $this->authorize('view', $bot);

            // Get or create settings for this bot
            $settings = $bot->settings ?? $this->createDefaultSettings($bot);

            // Merge AI settings from Bot model
            $data = $settings->toArray();
            $data['use_semantic_router'] = $bot->use_semantic_router ?? false;
            $data['semantic_router_threshold'] = $bot->semantic_router_threshold ?? 0.75;
            $data['semantic_router_fallback'] = $bot->semantic_router_fallback ?? 'llm';
            $data['use_confidence_cascade'] = $bot->use_confidence_cascade ?? false;
            $data['cascade_confidence_threshold'] = $bot->cascade_confidence_threshold ?? 0.7;
            $data['cascade_cheap_model'] = $bot->cascade_cheap_model ?? 'openai/gpt-4o-mini';
            $data['cascade_expensive_model'] = $bot->cascade_expensive_model ?? 'openai/gpt-4o';

            return response()->json([
                'data' => $data,
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

                // AI Settings (stored on Bot model)
                'use_semantic_router' => 'boolean',
                'semantic_router_threshold' => 'numeric|min:0|max:1',
                'semantic_router_fallback' => 'string|in:llm,default_intent',
                'use_confidence_cascade' => 'boolean',
                'cascade_confidence_threshold' => 'numeric|min:0|max:1',
                'cascade_cheap_model' => 'nullable|string|max:100',
                'cascade_expensive_model' => 'nullable|string|max:100',
            ]);

            // Separate AI settings from BotSetting fields
            $aiSettings = [
                'use_semantic_router',
                'semantic_router_threshold',
                'semantic_router_fallback',
                'use_confidence_cascade',
                'cascade_confidence_threshold',
                'cascade_cheap_model',
                'cascade_expensive_model',
            ];

            $botSettingData = collect($validated)->except($aiSettings)->toArray();
            $botAiData = collect($validated)->only($aiSettings)->toArray();

            // Get or create settings
            $settings = $bot->settings ?? $this->createDefaultSettings($bot);

            // Update BotSetting
            $settings->update($botSettingData);

            // Update Bot AI settings
            if (!empty($botAiData)) {
                $bot->update($botAiData);
            }

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
            // Note: multiple_bubbles fields use database defaults
            // They will be set automatically after migration runs
        ]);
    }
}
