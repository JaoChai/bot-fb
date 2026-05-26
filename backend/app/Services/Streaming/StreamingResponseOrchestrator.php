<?php

namespace App\Services\Streaming;

use App\Models\Bot;
use App\Models\Flow;
use App\Services\HybridSearchService;
use App\Services\IntentAnalysisService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\RAGService;
use App\Services\SemanticCacheService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * StreamingResponseOrchestrator - Pipeline runner for the chat-emulator SSE stream.
 *
 * Extracted from StreamController as part of Sprint 5 Task C (C2). Runs the entire
 * process_start → semantic cache → decision → KB → chat → done pipeline, emitting
 * SSE events through an injected `$onSseEvent` callback so the controller stays a
 * thin HTTP/SSE adapter.
 *
 * SSE event order, timing, and shape are byte-identical to the previous controller
 * implementation — frontend `useStreamingChat` depends on this contract.
 */
class StreamingResponseOrchestrator
{
    private string $openRouterBaseUrl;

    private string $openRouterSiteUrl;

    private string $openRouterSiteName;

    public function __construct(
        private OpenRouterService $openRouter,
        private HybridSearchService $hybridSearch,
        private IntentAnalysisService $intentAnalysis,
        private RAGService $ragService,
        private MultipleBubblesService $multipleBubbles,
        private ?SemanticCacheService $semanticCache = null,
    ) {
        $this->openRouterBaseUrl = config('services.openrouter.base_url') ?? 'https://openrouter.ai/api/v1';
        $this->openRouterSiteUrl = config('services.openrouter.site_url') ?? config('app.url') ?? '';
        $this->openRouterSiteName = config('services.openrouter.site_name') ?? config('app.name') ?? '';
    }

    /**
     * Run the full streaming pipeline.
     *
     * @param  callable  $onSseEvent  signature: `function (string $event, array $data): bool`
     *                                returns false if client disconnected.
     */
    public function run(
        Bot $bot,
        Flow $flow,
        string $message,
        array $conversationHistory,
        string $apiKey,
        array $memoryNotes,
        callable $onSseEvent,
    ): void {
        // Per-run state (replaces $this->metrics + $this->doneSent on the controller).
        $metrics = [
            'start_time' => microtime(true),
            'models_used' => [],
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'tool_calls' => 0,
        ];
        $doneSent = false;

        // Wrap the user callback so we can track whether `done` has been emitted.
        $emit = function (string $event, array $data) use ($onSseEvent, &$doneSent): bool {
            $result = $onSseEvent($event, $data);
            if ($event === 'done') {
                $doneSent = true;
            }

            return $result;
        };

        try {
            // === STEP 1: Process Start ===
            $emit('process_start', [
                'timestamp' => now()->toISOString(),
                'message' => $message,
            ]);

            // === SEMANTIC CACHE: Check for cached response ===
            if ($this->semanticCache?->isEnabled()) {
                $cacheResult = rescue(function () use ($bot, $message, $apiKey) {
                    return $this->semanticCache->get($bot, $message, $apiKey);
                }, null, report: false);

                if ($cacheResult) {
                    $emit('cache_hit', [
                        'match_type' => $cacheResult['cache_match_type'] ?? 'unknown',
                        'similarity' => $cacheResult['cache_similarity'] ?? 1.0,
                    ]);

                    // Stream cached content in chunks for natural feel
                    $content = $cacheResult['content'];
                    $chunkSize = 50;
                    $offset = 0;
                    while ($offset < mb_strlen($content)) {
                        $chunk = mb_substr($content, $offset, $chunkSize);
                        $emit('content', ['text' => $chunk]);
                        $offset += $chunkSize;
                        usleep(5000); // 5ms delay
                    }

                    // Send done
                    $totalTime = round((microtime(true) - $metrics['start_time']) * 1000);
                    $emit('done', [
                        'total_time_ms' => $totalTime,
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0,
                        'models_used' => [],
                        'tool_calls' => 0,
                        'from_cache' => true,
                    ]);

                    return;
                }
            }

            // === STANDARD MODE: Decision → KB → Chat ===

            // === STEP 2: Decision Model - Intent Analysis ===
            $intent = $this->runDecisionModel($bot, $message, $apiKey, $metrics, $emit);

            // === STEP 3: Knowledge Base Search ===
            $kbContext = $this->runKnowledgeBaseSearch($bot, $flow, $message, $intent, $emit);

            // === STEP 4: Chat Model - Generate Response ===
            $chatResponse = $this->runChatModel($bot, $flow, $message, $conversationHistory, $kbContext, $apiKey, $memoryNotes, $metrics, $emit);

            // === SEMANTIC CACHE: Save response ===
            if ($this->semanticCache?->isEnabled() && ! empty($chatResponse)) {
                rescue(function () use ($bot, $message, $chatResponse) {
                    $this->semanticCache->put($bot, $message, $chatResponse);
                }, null, report: false);
            }

            // === STEP 6: Done (if not already sent) ===
            if (! $doneSent) {
                $totalTime = round((microtime(true) - $metrics['start_time']) * 1000);
                $emit('done', [
                    'total_time_ms' => $totalTime,
                    'prompt_tokens' => $metrics['prompt_tokens'],
                    'completion_tokens' => $metrics['completion_tokens'],
                    'models_used' => $metrics['models_used'],
                    'tool_calls' => $metrics['tool_calls'],
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Stream error', [
                'bot_id' => $bot->id,
                'flow_id' => $flow->id,
                'error' => $e->getMessage(),
            ]);
            $emit('error', [
                'message' => $e->getMessage(),
                'step' => 'unknown',
            ]);
        } finally {
            // ALWAYS send done event if not already sent (ensures frontend never hangs)
            if (! $doneSent) {
                $emit('done', [
                    'total_time_ms' => round((microtime(true) - $metrics['start_time']) * 1000),
                    'prompt_tokens' => $metrics['prompt_tokens'],
                    'completion_tokens' => $metrics['completion_tokens'],
                    'models_used' => $metrics['models_used'],
                    'tool_calls' => $metrics['tool_calls'],
                    'error' => isset($e),
                ]);
            }
        }
    }

    /**
     * Step 2: Run Decision Model for Intent Analysis
     */
    private function runDecisionModel(Bot $bot, string $message, ?string $apiKey, array &$metrics, callable $onSseEvent): array
    {
        $decisionModel = $bot->decision_model;

        // Skip if no decision model configured
        if (empty($decisionModel)) {
            $onSseEvent('decision_skip', [
                'reason' => 'ไม่ได้ตั้งค่า Decision Model',
            ]);

            // Default: use KB if enabled, otherwise chat
            return [
                'intent' => $this->intentAnalysis->shouldUseKnowledgeBase($bot) ? 'knowledge' : 'chat',
                'confidence' => 1.0,
                'skipped' => true,
            ];
        }

        $startTime = microtime(true);
        $onSseEvent('decision_start', [
            'model' => $decisionModel,
        ]);

        // Use IntentAnalysisService for analysis
        $result = $this->intentAnalysis->analyzeIntent($bot, $message, [
            'validIntents' => ['chat', 'knowledge'],
            'includeExamples' => false,
            'useFallback' => true,
            'apiKey' => $apiKey,
        ]);

        $timeMs = round((microtime(true) - $startTime) * 1000);

        // Track metrics
        if (! empty($result['model_used'])) {
            $metrics['models_used'][] = $result['model_used'];
        }
        if (! empty($result['usage'])) {
            $metrics['prompt_tokens'] += $result['usage']['prompt_tokens'] ?? 0;
            $metrics['completion_tokens'] += $result['usage']['completion_tokens'] ?? 0;
        }

        // Check if it was skipped (no decision model)
        if (! empty($result['skipped'])) {
            return $result;
        }

        // Check if there was an error
        if (! empty($result['error'])) {
            $onSseEvent('decision_fallback', [
                'original_model' => $decisionModel,
                'fallback_model' => $bot->fallback_decision_model,
                'error' => $result['error'],
            ]);
        }

        $onSseEvent('decision_result', [
            'intent' => $result['intent'],
            'confidence' => $result['confidence'],
            'model' => $result['model_used'] ?? $decisionModel,
            'tokens' => ($result['usage']['prompt_tokens'] ?? 0) + ($result['usage']['completion_tokens'] ?? 0),
            'time_ms' => $timeMs,
        ]);

        return $result;
    }

    /**
     * Step 3: Run Knowledge Base Search
     */
    private function runKnowledgeBaseSearch(Bot $bot, Flow $flow, string $message, array $intent, callable $onSseEvent): string
    {
        // Check if we should use KB
        $shouldUseKB = ($intent['intent'] === 'knowledge' || isset($intent['skipped']))
            && $this->intentAnalysis->shouldUseKnowledgeBase($bot);

        // Also check flow-level KBs
        $flowKBs = $flow->knowledgeBases;
        $hasFlowKBs = $flowKBs && $flowKBs->isNotEmpty();

        if (! $shouldUseKB && ! $hasFlowKBs) {
            $onSseEvent('kb_skip', [
                'reason' => $this->intentAnalysis->shouldUseKnowledgeBase($bot)
                    ? 'Intent ไม่ต้องการ Knowledge Base'
                    : 'ไม่ได้เปิดใช้งาน Knowledge Base',
            ]);

            return '';
        }

        $startTime = microtime(true);

        // Prepare KB list for event
        $kbList = [];
        if ($hasFlowKBs) {
            foreach ($flowKBs as $kb) {
                $kbList[] = ['id' => $kb->id, 'name' => $kb->name];
            }
        } elseif ($bot->knowledgeBase) {
            $kbList[] = ['id' => $bot->knowledgeBase->id, 'name' => $bot->knowledgeBase->name];
        }

        $onSseEvent('kb_search', [
            'knowledge_bases' => $kbList,
            'query' => substr($message, 0, 100).(strlen($message) > 100 ? '...' : ''),
        ]);

        try {
            $results = collect();
            $kbResults = [];

            // Get API key: User Settings > ENV
            $embeddingApiKey = $bot->user?->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

            // Search flow-level KBs (many-to-many)
            if ($hasFlowKBs) {
                $kbConfigs = $flowKBs->map(fn ($kb) => [
                    'id' => $kb->id,
                    'name' => $kb->name,
                    'kb_top_k' => $kb->pivot->kb_top_k ?? 5,
                    'kb_similarity_threshold' => $kb->pivot->kb_similarity_threshold ?? 0.7,
                ])->toArray();

                $results = $this->hybridSearch->searchMultiple(
                    kbConfigs: $kbConfigs,
                    query: $message,
                    totalLimit: config('rag.max_results', 5),
                    apiKey: $embeddingApiKey
                );

                // Group results by KB
                foreach ($flowKBs as $kb) {
                    $kbChunks = $results->filter(fn ($r) => $r['knowledge_base_id'] === $kb->id);
                    if ($kbChunks->isNotEmpty()) {
                        $kbResults[] = [
                            'kb_name' => $kb->name,
                            'chunks_found' => $kbChunks->count(),
                            'top_relevance' => round($kbChunks->max('similarity') * 100),
                        ];
                    }
                }
            }
            // Search bot-level KB (legacy)
            elseif ($bot->knowledgeBase) {
                $results = $this->hybridSearch->search(
                    knowledgeBaseId: $bot->knowledgeBase->id,
                    query: $message,
                    limit: $bot->kb_max_results ?? config('rag.max_results', 3),
                    threshold: $bot->kb_relevance_threshold ?? config('rag.default_threshold', 0.7),
                    apiKey: $embeddingApiKey
                );

                if ($results->isNotEmpty()) {
                    $kbResults[] = [
                        'kb_name' => $bot->knowledgeBase->name,
                        'chunks_found' => $results->count(),
                        'top_relevance' => round($results->max('similarity') * 100),
                    ];
                }
            }

            $timeMs = round((microtime(true) - $startTime) * 1000);

            $onSseEvent('kb_result', [
                'results' => $kbResults,
                'total_chunks' => $results->count(),
                'search_mode' => $this->hybridSearch->isEnabled() ? 'hybrid' : 'semantic',
                'time_ms' => $timeMs,
            ]);

            if ($results->isEmpty()) {
                return '';
            }

            // Format context for prompt (delegate to RAGService)
            return $this->ragService->formatKnowledgeBaseContext($results);

        } catch (\Exception $e) {
            Log::error('KB search failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            $onSseEvent('kb_result', [
                'results' => [],
                'total_chunks' => 0,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Step 4: Run Chat Model for Response Generation (with streaming)
     *
     * @return string The collected response content for Second AI processing
     */
    private function runChatModel(
        Bot $bot,
        Flow $flow,
        string $message,
        array $conversationHistory,
        string $kbContext,
        ?string $apiKey,
        array $memoryNotes,
        array &$metrics,
        callable $onSseEvent,
    ): string {
        // Get models from Bot Settings
        $chatModel = $this->getChatModel($bot);
        $fallbackChatModel = $this->getFallbackChatModel($bot);

        // Build system prompt with memory prefix (same format as RAGService)
        $systemPrompt = $this->buildMemoryPrefix($memoryNotes)
            .($flow->system_prompt ?: $this->getDefaultSystemPrompt($bot));
        $systemPrompt = $this->ragService->injectStockStatus($systemPrompt);
        if (! empty($kbContext)) {
            $systemPrompt .= "\n\n".$kbContext;
        }

        $onSseEvent('chat_start', [
            'model' => $chatModel,
            'system_prompt_length' => strlen($systemPrompt),
            'has_kb_context' => ! empty($kbContext),
        ]);

        // Build messages array
        $messages = $this->buildMessages($systemPrompt, $conversationHistory, $message);

        $startTime = microtime(true);
        $usedFallback = false;
        $collectedResponse = '';

        try {
            $collectedResponse = $this->streamFromOpenRouter($messages, $chatModel, $apiKey, $flow->temperature, $flow->max_tokens, $metrics, $onSseEvent);
            $metrics['models_used'][] = $chatModel;
        } catch (\Exception $e) {
            // Try fallback model
            if ($fallbackChatModel) {
                $onSseEvent('chat_fallback', [
                    'original_model' => $chatModel,
                    'fallback_model' => $fallbackChatModel,
                    'error' => $e->getMessage(),
                ]);

                try {
                    $collectedResponse = $this->streamFromOpenRouter($messages, $fallbackChatModel, $apiKey, $flow->temperature, $flow->max_tokens, $metrics, $onSseEvent);
                    $metrics['models_used'][] = $fallbackChatModel;
                    $usedFallback = true;
                } catch (\Exception $fallbackError) {
                    throw new \Exception('Both primary and fallback models failed: '.$fallbackError->getMessage());
                }
            } else {
                throw $e;
            }
        }

        return $collectedResponse;
    }

    /**
     * Stream response from OpenRouter using Guzzle with true streaming.
     *
     * @return string The full collected response content
     */
    private function streamFromOpenRouter(array $messages, string $model, ?string $apiKey, ?float $temperature, ?int $maxTokens, array &$metrics, callable $onSseEvent): string
    {
        $client = new Client([
            'timeout' => (int) config('services.openrouter.stream_timeout', 120),
            'connect_timeout' => (int) config('services.openrouter.connect_timeout', 10),
        ]);

        $baseUrl = $this->openRouterBaseUrl;
        $apiKey = $apiKey ?: config('services.openrouter.api_key');

        $response = $client->post($baseUrl.'/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => $this->openRouterSiteUrl,
                'X-Title' => $this->openRouterSiteName,
            ],
            'json' => [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
                'temperature' => $temperature ?? 0.7,
                'max_tokens' => $maxTokens ?? 4096,
            ],
            'stream' => true,
        ]);

        $body = $response->getBody();
        $buffer = '';
        $collectedContent = '';

        while (! $body->eof()) {
            $chunk = $body->read(1024);
            if (empty($chunk)) {
                continue;
            }

            $buffer .= $chunk;

            // Process complete SSE lines
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);
                if (empty($line) || strpos($line, ':') === 0) {
                    continue;
                }

                if (strpos($line, 'data: ') === 0) {
                    $data = substr($line, 6);

                    if ($data === '[DONE]') {
                        return $collectedContent;
                    }

                    $json = json_decode($data, true);
                    if ($json && isset($json['choices'][0]['delta']['content'])) {
                        $content = $json['choices'][0]['delta']['content'];
                        $collectedContent .= $content;
                        $onSseEvent('content', ['text' => $content]);
                    }

                    // Track usage if available
                    if ($json && isset($json['usage'])) {
                        $metrics['prompt_tokens'] += $json['usage']['prompt_tokens'] ?? 0;
                        $metrics['completion_tokens'] += $json['usage']['completion_tokens'] ?? 0;
                    }

                    // Check for errors
                    if ($json && isset($json['error'])) {
                        throw new \Exception($json['error']['message'] ?? 'Unknown API error');
                    }
                }
            }

            if (connection_aborted()) {
                Log::info('Stream aborted by client');

                return $collectedContent;
            }
        }

        return $collectedContent;
    }

    private function getChatModel(Bot $bot): string
    {
        return $bot->primary_chat_model
            ?: $bot->fallback_chat_model
            ?: config('services.openrouter.default_model', 'anthropic/claude-3.5-sonnet');
    }

    private function getFallbackChatModel(Bot $bot): ?string
    {
        return $bot->fallback_chat_model
            ?: config('services.openrouter.fallback_model');
    }

    private function buildMessages(string $systemPrompt, array $history, string $message): array
    {
        $messages = [];

        if ($systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        foreach ($history as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if (! empty($content) && in_array($role, ['user', 'assistant'])) {
                $messages[] = [
                    'role' => $role,
                    'content' => $content,
                ];
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        return $messages;
    }

    /**
     * Build memory notes prefix for system prompt (same format as RAGService).
     */
    private function buildMemoryPrefix(array $memoryNotes): string
    {
        if (empty($memoryNotes)) {
            return '';
        }

        $prefix = "## Memory:\n";
        foreach ($memoryNotes as $content) {
            $prefix .= "- {$content}\n";
        }
        $prefix .= "---\n\n";

        return $prefix;
    }

    private function getDefaultSystemPrompt(Bot $bot): string
    {
        return <<<PROMPT
You are a helpful AI assistant for {$bot->name}.
Be friendly, professional, and helpful.
Respond in the same language as the user's message.
If you don't know something, be honest about it.
Keep responses concise but informative.
PROMPT;
    }
}
