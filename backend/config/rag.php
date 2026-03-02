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

    'max_conversation_history' => env('RAG_MAX_CONVERSATION_HISTORY', 20),

    'max_document_size' => env('RAG_MAX_DOCUMENT_SIZE', 50 * 1024 * 1024), // 50MB

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
    | Semantic Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Caches RAG responses using PostgreSQL + pgvector for semantic matching.
    | Similar queries return cached responses, reducing API costs and latency.
    |
    | Benefits:
    | - Reduces API costs by 30-50% (cache hit rate depends on query patterns)
    | - Reduces latency from 2-3s to ~100ms for cached queries
    | - Uses existing PostgreSQL (no extra infrastructure)
    |
    | How it works:
    | 1. Exact match check (fast, no API call)
    | 2. If no exact match, semantic similarity search using pgvector
    | 3. If similarity > threshold → return cached response
    | 4. If no match → generate new response → save to cache
    |
    */
    'semantic_cache' => [
        // Enable/disable semantic cache
        'enabled' => env('RAG_SEMANTIC_CACHE_ENABLED', true),

        // Similarity threshold for cache hit (0.0 - 1.0)
        // Higher = stricter matching, lower hit rate but more accurate
        // Recommended: 0.90-0.95 for most use cases
        'similarity_threshold' => env('RAG_SEMANTIC_CACHE_THRESHOLD', 0.92),

        // Cache TTL in minutes
        // Longer TTL = higher hit rate but potentially stale data
        // Recommended: 30-60 minutes for dynamic content, 60-1440 for static
        'ttl_minutes' => env('RAG_SEMANTIC_CACHE_TTL', 60),

        // Try exact match first before semantic search
        // Saves embedding API call if query is exactly the same
        'exact_match_first' => env('RAG_SEMANTIC_CACHE_EXACT_FIRST', true),

        // Cache cleanup interval in hours (run via scheduler)
        'cleanup_interval_hours' => env('RAG_SEMANTIC_CACHE_CLEANUP', 6),

        // Maximum cache entries per bot (prevents unbounded growth)
        // Oldest entries are removed when limit is reached
        'max_entries_per_bot' => env('RAG_SEMANTIC_CACHE_MAX_ENTRIES', 10000),

        // Skip cache for short messages (context-dependent like "ยืนยัน", "ครับ")
        // Messages with mb_strlen <= this value will bypass cache entirely
        'skip_if_length_lte' => (int) env('RAG_SEMANTIC_CACHE_SKIP_LENGTH', 20),

        // Regex patterns for context-dependent keywords (anchored = standalone only)
        // Messages matching any pattern bypass cache even if longer than skip_if_length_lte
        'skip_patterns' => [
            '/^(ยืนยัน|ยอมรับ|ตกลง|โอเค|ใช่|ถูกต้อง|ครับ|ค่ะ|จ้า|จ้ะ|ได้เลย|เอา|สั่ง|สั่งเลย|ok|yes|sure|confirm)$/iu',
            '/^(ยกเลิก|ไม่เอา|ไม่ต้อง|ไม่ใช่|เปลี่ยน|แก้ไข|cancel|no|reject)$/iu',
            '/^(จ่าย|โอน|โอนแล้ว|ชำระ|ชำระแล้ว|pay|paid|transfer|sent)$/iu',
        ],

        // Skip cache when conversation has active history (ongoing conversation = context-dependent)
        // Default: false — conditions 1-3 (length, patterns, memory_notes) are sufficient
        // Set to true only if you want maximum safety at the cost of disabling cache for ~95% of messages
        'skip_if_has_history' => env('RAG_SEMANTIC_CACHE_SKIP_HAS_HISTORY', false),
    ],

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
    | Semantic Router Configuration
    |--------------------------------------------------------------------------
    |
    | Fast intent classification using vector similarity instead of LLM calls.
    | Based on RouteLLM research: https://github.com/lm-sys/RouteLLM
    |
    | Performance: 50-100ms vs 500-2000ms (LLM), 10-20x faster
    | Cost: ~$0.0001/call (embedding only) vs $0.001-0.01 (LLM)
    |
    */
    'semantic_router' => [
        // Enable/disable semantic router (falls back to LLM if disabled)
        'enabled' => env('RAG_SEMANTIC_ROUTER_ENABLED', true),

        // Minimum similarity score to accept semantic classification
        // Below this threshold, falls back to LLM decision model
        'default_threshold' => env('RAG_SEMANTIC_ROUTER_THRESHOLD', 0.75),

        // Fallback behavior when confidence is below threshold
        // Options: 'llm' (use LLM decision model), 'default_intent' (default to 'chat')
        'fallback' => env('RAG_SEMANTIC_ROUTER_FALLBACK', 'llm'),

        // Cache TTL for route embeddings (seconds)
        'cache_ttl' => env('RAG_SEMANTIC_ROUTER_CACHE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidence-Based Cascade Configuration
    |--------------------------------------------------------------------------
    |
    | Cost-effective LLM routing: try cheap model first, escalate to expensive
    | only when confidence is low.
    |
    | Based on RouteLLM research: https://lmsys.org/blog/2024-07-01-routellm/
    | Can reduce costs by 50-85% while maintaining 95% response quality.
    |
    */
    'confidence_cascade' => [
        // Enable/disable confidence cascade
        'enabled' => env('RAG_CASCADE_ENABLED', false),

        // Confidence threshold to accept cheap model response
        // Below this threshold, escalates to expensive model
        'threshold' => env('RAG_CASCADE_THRESHOLD', 0.7),

        // Cheap model for initial attempt
        'cheap_model' => env('RAG_CASCADE_CHEAP_MODEL', 'openai/gpt-4o-mini'),

        // Expensive model for escalation
        'expensive_model' => env('RAG_CASCADE_EXPENSIVE_MODEL', 'openai/gpt-4o'),
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

    /*
    |--------------------------------------------------------------------------
    | Corrective RAG (CRAG) Configuration
    |--------------------------------------------------------------------------
    |
    | Evaluates retrieval quality and takes corrective action when results
    | are ambiguous or incorrect. Improves response accuracy by ~10-20%.
    |
    | Based on: "Corrective Retrieval Augmented Generation" (2024)
    |
    */
    'crag' => [
        // Enable/disable CRAG evaluation
        'enabled' => env('RAG_CRAG_ENABLED', false),

        // Evaluation mode: 'heuristics' (fast), 'llm' (accurate), 'hybrid' (balanced)
        'evaluation_mode' => env('RAG_CRAG_MODE', 'heuristics'),

        // LLM model for evaluation (when using 'llm' or 'hybrid' mode)
        'evaluation_model' => env('RAG_CRAG_MODEL', 'openai/gpt-4o-mini'),

        // Threshold for "correct" grade (use results directly)
        'correct_threshold' => env('RAG_CRAG_CORRECT_THRESHOLD', 0.7),

        // Threshold for "ambiguous" grade (rewrite query)
        'ambiguous_threshold' => env('RAG_CRAG_AMBIGUOUS_THRESHOLD', 0.3),

        // Maximum query rewrite attempts for ambiguous results
        'max_rewrite_attempts' => env('RAG_CRAG_MAX_REWRITES', 2),

        // Action when grade is "incorrect": 'skip_kb' or 'fallback_general'
        'incorrect_action' => env('RAG_CRAG_INCORRECT_ACTION', 'skip_kb'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Second AI Configuration
    |--------------------------------------------------------------------------
    */
    'second_ai' => [
        'pipeline_timeout' => (int) env('SECOND_AI_PIPELINE_TIMEOUT', 25),
        'http_timeout' => (int) env('SECOND_AI_HTTP_TIMEOUT', 15),
        'max_tokens' => (int) env('SECOND_AI_MAX_TOKENS', 1000),
    ],
];
