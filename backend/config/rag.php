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

    /*
    |--------------------------------------------------------------------------
    | Hybrid Search Configuration
    |--------------------------------------------------------------------------
    |
    | Hybrid search combines semantic (vector) search with keyword (full-text)
    | search using Reciprocal Rank Fusion (RRF) for ~48% better retrieval.
    |
    | Requires PostgreSQL with the GIN index on document_chunks.content.
    |
    */
    'hybrid_search' => [
        // Enable/disable hybrid search (falls back to semantic-only if disabled)
        'enabled' => env('RAG_HYBRID_ENABLED', true),

        // RRF constant (k). Standard value is 60.
        // Higher k = more weight to lower-ranked items.
        'rrf_k' => env('RAG_RRF_K', 60),

        // Multiplier for candidate retrieval before RRF fusion
        // e.g., if limit=5 and multiplier=4, fetch 20 candidates from each method
        'candidate_multiplier' => env('RAG_CANDIDATE_MULTIPLIER', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reranking Configuration (Phase 2)
    |--------------------------------------------------------------------------
    |
    | Reranking uses a cross-encoder model to rescore results for
    | ~67% better accuracy. Supports Jina AI (recommended) or Cohere.
    |
    | Jina AI: Free 10M tokens, 96.30 Recall@20
    | Cohere: 1000 free calls/month, 96.10 Recall@20
    |
    */
    'reranking' => [
        // Enable/disable reranking
        'enabled' => env('RAG_RERANKING_ENABLED', false),

        // Reranking provider: 'jina' or 'cohere'
        'provider' => env('RAG_RERANKER_PROVIDER', 'jina'),

        // Number of candidates to fetch before reranking
        'candidates' => env('RAG_RERANK_CANDIDATES', 20),

        // Final number of results after reranking
        'top_n' => env('RAG_RERANK_TOP_N', 5),
    ],
];
