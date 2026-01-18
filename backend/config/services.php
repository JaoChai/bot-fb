<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'anthropic/claude-3.5-sonnet'),
        'fallback_model' => env('OPENROUTER_FALLBACK_MODEL', 'openai/gpt-4o-mini'),
        'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL')),
        'site_name' => env('OPENROUTER_SITE_NAME', env('APP_NAME', 'BotFacebook')),
        'timeout' => env('OPENROUTER_TIMEOUT', 60),
        'max_tokens' => env('OPENROUTER_MAX_TOKENS', 4096),
        // Provider preferences for routing optimization (OpenRouter Best Practice)
        'provider_preferences' => [
            // Max acceptable latency in seconds (null = no preference)
            'preferred_max_latency' => env('OPENROUTER_MAX_LATENCY'),
            // Min tokens per second throughput (null = no preference)
            'preferred_min_throughput' => env('OPENROUTER_MIN_THROUGHPUT'),
            // Data collection policy: 'allow' or 'deny'
            'data_collection' => env('OPENROUTER_DATA_COLLECTION', 'deny'),
        ],
    ],

    'line' => [
        'channel_id' => env('LINE_CHANNEL_ID'),
        'channel_secret' => env('LINE_CHANNEL_SECRET'),
        'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v21.0'),
    ],

    'embeddings' => [
        'model' => env('EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
        'dimensions' => env('EMBEDDING_DIMENSIONS', 1536),
        'chunk_size' => env('EMBEDDING_CHUNK_SIZE', 500),
        'chunk_overlap' => env('EMBEDDING_CHUNK_OVERLAP', 50),
        'relevance_threshold' => env('EMBEDDING_RELEVANCE_THRESHOLD', 0.7),
    ],

    'jina' => [
        'api_key' => env('JINA_API_KEY'),
        'base_url' => env('JINA_BASE_URL', 'https://api.jina.ai/v1'),
        'rerank_model' => env('JINA_RERANK_MODEL', 'jina-reranker-v2-base-multilingual'),
        'timeout' => env('JINA_TIMEOUT', 30),
    ],

];
