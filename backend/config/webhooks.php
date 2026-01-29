<?php

/**
 * Webhook configuration for feature flags and settings.
 *
 * Phase 6 of chat refactoring - gradual rollout of new webhook handlers.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Use New Handlers
    |--------------------------------------------------------------------------
    |
    | Enable the new extracted webhook handlers instead of the monolithic
    | ProcessLINEWebhook job. Set to true to enable the new architecture.
    |
    | Default: false (use existing monolithic job)
    |
    */
    'use_new_handlers' => env('WEBHOOK_NEW_HANDLERS', false),

    /*
    |--------------------------------------------------------------------------
    | Handler Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for individual webhook handlers.
    |
    */
    'handlers' => [
        'line' => [
            // Enable detailed logging for debugging
            'debug_logging' => env('WEBHOOK_LINE_DEBUG', false),

            // Timeout for profile sync operations (seconds)
            'profile_sync_timeout' => env('WEBHOOK_LINE_PROFILE_TIMEOUT', 10),

            // Enable AI response generation
            'ai_enabled' => env('WEBHOOK_LINE_AI_ENABLED', true),

            // Maximum media size to process (bytes)
            'max_media_size' => env('WEBHOOK_LINE_MAX_MEDIA_SIZE', 10 * 1024 * 1024), // 10MB
        ],

        'telegram' => [
            'debug_logging' => env('WEBHOOK_TELEGRAM_DEBUG', false),
            'ai_enabled' => env('WEBHOOK_TELEGRAM_AI_ENABLED', true),
        ],
    ],
];
