<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RAG (Retrieval Augmented Generation) Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control how the bot integrates Knowledge Base
    | information into AI responses.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Relevance Threshold
    |--------------------------------------------------------------------------
    |
    | Minimum similarity score (0-1) for a KB chunk to be included in context.
    | Higher values = stricter matching, lower = more lenient.
    |
    | Recommended ranges:
    | - 0.8+ : Very strict, only highly relevant content
    | - 0.7  : Balanced (default)
    | - 0.5-0.6 : Lenient, may include loosely related content
    |
    */
    'default_threshold' => env('RAG_THRESHOLD', 0.70),

    /*
    |--------------------------------------------------------------------------
    | Maximum Results
    |--------------------------------------------------------------------------
    |
    | Maximum number of KB chunks to include in the prompt context.
    | More results = more context but higher token usage.
    |
    */
    'max_results' => env('RAG_MAX_RESULTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Maximum Context Characters
    |--------------------------------------------------------------------------
    |
    | Maximum total characters for KB context in the prompt.
    | Prevents context from overwhelming the system prompt.
    |
    */
    'max_context_chars' => env('RAG_MAX_CONTEXT_CHARS', 4000),

    /*
    |--------------------------------------------------------------------------
    | Context Template
    |--------------------------------------------------------------------------
    |
    | Language template for KB context formatting.
    | Options: 'thai', 'english'
    |
    */
    'context_template' => env('RAG_CONTEXT_TEMPLATE', 'thai'),

    /*
    |--------------------------------------------------------------------------
    | Enable RAG Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, RAG operations will be logged for debugging.
    |
    */
    'logging_enabled' => env('RAG_LOGGING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | Time in seconds to cache KB search results for identical queries.
    | Set to 0 to disable caching.
    |
    */
    'cache_ttl' => env('RAG_CACHE_TTL', 60),
];
