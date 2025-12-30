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

    /*
    |--------------------------------------------------------------------------
    | Query Enhancement Configuration (Phase 3)
    |--------------------------------------------------------------------------
    |
    | Uses LLM to expand and rewrite queries for better retrieval.
    | Generates multiple search variations from a single query.
    |
    | Research shows: +20-48% recall improvement (Haystack, Microsoft 2024)
    | Cost: ~$0.00005/query with GPT-4o-mini
    |
    */
    'query_enhancement' => [
        // Enable/disable query enhancement
        'enabled' => env('RAG_QUERY_ENHANCEMENT_ENABLED', false),

        // LLM model for query expansion (cheap, fast model recommended)
        'model' => env('RAG_QUERY_ENHANCEMENT_MODEL', 'openai/gpt-4o-mini'),

        // Maximum number of query variations to generate
        'max_variations' => env('RAG_QUERY_MAX_VARIATIONS', 3),

        // Minimum query length to trigger enhancement (chars)
        'min_query_length' => 2,

        // Timeout in seconds for enhancement LLM call
        'timeout' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chain-of-Thought (CoT) Configuration
    |--------------------------------------------------------------------------
    |
    | Automatically detects complex questions and instructs the LLM to use
    | step-by-step reasoning for better accuracy.
    |
    | Research shows: CoT improves accuracy on reasoning tasks by 10-30%
    | Cost: No additional API calls (uses heuristics-based detection)
    |
    */
    'chain_of_thought' => [
        // Enable/disable Chain-of-Thought auto-detection
        'enabled' => env('RAG_COT_ENABLED', true),

        // Minimum complexity score to trigger CoT (0-5 scale)
        // Score is calculated from: message length, question marks, keywords
        'complexity_threshold' => env('RAG_COT_THRESHOLD', 2),

        // Multiplier for max_tokens when CoT is active
        // Complex questions need more tokens for reasoning
        'max_tokens_multiplier' => env('RAG_COT_TOKENS_MULTIPLIER', 1.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contextual Retrieval Configuration
    |--------------------------------------------------------------------------
    |
    | Adds contextual information to chunks before embedding for better
    | retrieval accuracy. Based on Anthropic's Contextual Retrieval technique.
    |
    | Research shows: Reduces retrieval failure by ~49%
    | Cost: ~$0.001-0.005 per document (1 summary + N/5 context calls)
    |
    */
    'contextual_retrieval' => [
        // Enable/disable contextual retrieval for new documents
        'enabled' => env('RAG_CONTEXTUAL_ENABLED', true),

        // LLM model for context generation (cheap, fast model recommended)
        // Options: openai/gpt-4o-mini (cheaper), openai/gpt-5-mini (better reasoning)
        'model' => env('RAG_CONTEXT_MODEL', 'openai/gpt-5-mini'),

        // Maximum tokens for document summary
        'max_summary_tokens' => env('RAG_CONTEXT_SUMMARY_TOKENS', 200),

        // Maximum tokens for each chunk context
        'max_context_tokens' => env('RAG_CONTEXT_CHUNK_TOKENS', 100),

        // Number of chunks to process per LLM call (batch size)
        'batch_size' => env('RAG_CONTEXT_BATCH_SIZE', 5),

        // Timeout in seconds for context generation
        'timeout' => 30,
    ],
];
