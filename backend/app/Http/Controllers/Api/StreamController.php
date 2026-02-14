<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\User;
use App\Services\Agent\AgentLoopConfig;
use App\Services\Agent\AgentLoopService;
use App\Services\Agent\SseAgentCallbacks;
use App\Services\AgentSafetyService;
use App\Services\CostTrackingService;
use App\Services\HybridSearchService;
use App\Services\IntentAnalysisService;
use App\Services\OpenRouterService;
use App\Services\RAGService;
use App\Services\SecondAI\SecondAIService;
use App\Services\MultipleBubblesService;
use App\Services\SemanticCacheService;
use App\Services\ToolService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * StreamController - System Process Logging for Chat Emulator
 *
 * This controller implements SSE streaming that shows the full process:
 * 1. Decision Model - Intent Analysis
 * 2. Knowledge Base - Search (if enabled)
 * 3. Chat Model - Response Generation
 *
 * All with real-time SSE events showing each step.
 */
class StreamController extends Controller
{
    protected OpenRouterService $openRouter;
    protected HybridSearchService $hybridSearch;
    protected ToolService $toolService;
    protected CostTrackingService $costTracking;
    protected AgentSafetyService $agentSafety;
    protected SecondAIService $secondAI;
    protected IntentAnalysisService $intentAnalysis;
    protected RAGService $ragService;
    protected MultipleBubblesService $multipleBubbles;
    protected AgentLoopService $agentLoopService;
    protected ?SemanticCacheService $semanticCache;

    protected string $openRouterBaseUrl;
    protected string $openRouterSiteUrl;
    protected string $openRouterSiteName;

    // Track process metrics (reset at start of each request for Octane safety)
    protected array $metrics = [
        'start_time' => 0,
        'models_used' => [],
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'tool_calls' => 0,
    ];

    // Track if done event has been sent (reset at start of each request for Octane safety)
    protected bool $doneSent = false;

    // KB search metadata for smart routing decisions
    protected array $lastKbSearchMeta = [];

    public function __construct(
        OpenRouterService $openRouter,
        HybridSearchService $hybridSearch,
        ToolService $toolService,
        CostTrackingService $costTracking,
        AgentSafetyService $agentSafety,
        SecondAIService $secondAI,
        IntentAnalysisService $intentAnalysis,
        RAGService $ragService,
        MultipleBubblesService $multipleBubbles,
        AgentLoopService $agentLoopService,
        ?SemanticCacheService $semanticCache = null
    ) {
        $this->openRouter = $openRouter;
        $this->hybridSearch = $hybridSearch;
        $this->toolService = $toolService;
        $this->costTracking = $costTracking;
        $this->agentSafety = $agentSafety;
        $this->secondAI = $secondAI;
        $this->intentAnalysis = $intentAnalysis;
        $this->ragService = $ragService;
        $this->multipleBubbles = $multipleBubbles;
        $this->agentLoopService = $agentLoopService;
        $this->semanticCache = $semanticCache;

        $this->openRouterBaseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->openRouterSiteUrl = config('services.openrouter.site_url', config('app.url'));
        $this->openRouterSiteName = config('services.openrouter.site_name', config('app.name'));
    }

    /**
     * Stream AI response with System Process Logging.
     * Shows each step: Decision Model, KB Search, Chat Model
     */
    public function streamTest(Request $request, int $botId, int $flowId): StreamedResponse
    {
        // 1. Manual authentication (before streaming starts)
        $user = $this->authenticateFromToken($request);
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        // 2. Validate input
        $message = $request->input('message');
        $conversationHistory = $request->input('conversation_history', []);
        $conversationId = $request->input('conversation_id');
        // Limit conversation history to prevent excessive token usage
        $maxHistory = (int) config('rag.max_conversation_history', 20);
        $conversationHistory = array_slice($conversationHistory, -$maxHistory);

        if (empty($message)) {
            return $this->errorResponse('Message is required', 400);
        }

        if (strlen($message) > 2000) {
            return $this->errorResponse('Message too long (max 2000 characters)', 400);
        }

        // 3. Load bot and flow (with authorization)
        $bot = Bot::find($botId);
        if (!$bot || $bot->user_id !== $user->id) {
            return $this->errorResponse('Bot not found', 404);
        }

        $flow = Flow::where('id', $flowId)->where('bot_id', $botId)->first();
        if (!$flow) {
            return $this->errorResponse('Flow not found', 404);
        }

        // 4. Get API key: User Settings > ENV
        $apiKey = $bot->user?->settings?->getOpenRouterApiKey() ?? config('services.openrouter.api_key');
        if (empty($apiKey)) {
            return $this->errorResponse('No API key configured. Please set up in Settings page.', 422);
        }

        // 5. Load memory notes from conversation (if provided)
        $memoryNotes = [];
        if ($conversationId) {
            $conversation = Conversation::where('id', $conversationId)
                ->where('bot_id', $botId)
                ->first();
            if ($conversation) {
                $memoryNotes = collect($conversation->memory_notes ?? [])
                    ->where('type', 'memory')
                    ->pluck('content')
                    ->all();
            }
        }

        // 6. Create SSE response
        return new StreamedResponse(function () use ($bot, $flow, $message, $conversationHistory, $apiKey, $user, $memoryNotes) {
            // Disable output buffering for streaming
            while (ob_get_level()) {
                ob_end_clean();
            }

            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
            @ini_set('implicit_flush', true);

            // Reset ALL state for this request (Octane safety: prevents cross-request data leak)
            $this->metrics = [
                'start_time' => microtime(true),
                'models_used' => [],
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'tool_calls' => 0,
            ];
            $this->doneSent = false;

            // Eager load knowledgeBases to prevent lazy loading in runKnowledgeBaseSearch()
            $flow->loadMissing('knowledgeBases');

            try {
                // === STEP 1: Process Start ===
                $this->sendSSE('process_start', [
                    'timestamp' => now()->toISOString(),
                    'message' => $message,
                    'agentic_mode' => $flow->agentic_mode,
                ]);

                // === STEP 1.5: Injection Detection (Security Guardrail) ===
                if ($flow->second_ai_enabled) {
                    $injectionResult = $this->secondAI->checkUserInput($message, $flow);

                    if ($injectionResult->isBlocked()) {
                        $this->sendSSE('injection_blocked', [
                            'risk_score' => $injectionResult->riskScore,
                            'patterns' => $injectionResult->getPatternNames(),
                            'message' => $injectionResult->message,
                        ]);

                        // Send done event and exit (doneSent will be set by sendSSE)
                        $this->sendSSE('done', [
                            'total_time_ms' => round((microtime(true) - $this->metrics['start_time']) * 1000),
                            'prompt_tokens' => 0,
                            'completion_tokens' => 0,
                            'models_used' => [],
                            'tool_calls' => 0,
                            'blocked' => true,
                        ]);
                        return;
                    }

                    // Log flagged but allowed inputs
                    if ($injectionResult->isFlagged()) {
                        $this->sendSSE('injection_flagged', [
                            'risk_score' => $injectionResult->riskScore,
                            'patterns' => $injectionResult->getPatternNames(),
                        ]);
                    }
                }

                // === SEMANTIC CACHE: Check for cached response ===
                if (!$flow->agentic_mode && $this->semanticCache?->isEnabled()) {
                    $cacheResult = rescue(function () use ($bot, $message, $apiKey) {
                        return $this->semanticCache->get($bot, $message, $apiKey);
                    }, null, report: false);

                    if ($cacheResult) {
                        $this->sendSSE('cache_hit', [
                            'match_type' => $cacheResult['cache_match_type'] ?? 'unknown',
                            'similarity' => $cacheResult['cache_similarity'] ?? 1.0,
                        ]);

                        // Stream cached content in chunks for natural feel
                        $content = $cacheResult['content'];
                        $chunkSize = 50;
                        $offset = 0;
                        while ($offset < mb_strlen($content)) {
                            $chunk = mb_substr($content, $offset, $chunkSize);
                            $this->sendSSE('content', ['text' => $chunk]);
                            $offset += $chunkSize;
                            usleep(5000); // 5ms delay
                        }

                        // Send done
                        $totalTime = round((microtime(true) - $this->metrics['start_time']) * 1000);
                        $this->sendSSE('done', [
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

                // === AGENTIC MODE: KB-First + Smart Routing ===
                if ($flow->agentic_mode && !empty($flow->enabled_tools)) {
                    // KB-First: Pre-search
                    $kbContext = $this->runAgentKnowledgeBaseSearch($bot, $flow, $message);
                    $kbMeta = $this->lastKbSearchMeta;

                    // Smart Routing: Multi-signal via AgentLoopService
                    $complexity = $this->ragService->detectComplexity($message);
                    $toolIntent = $this->ragService->detectToolIntent($message, $flow->enabled_tools ?? []);
                    $configuredThreshold = $flow->knowledgeBases->first()?->pivot->kb_similarity_threshold ?? 0.7;

                    $routing = $this->agentLoopService->shouldUseAgentLoop(
                        $complexity,
                        $toolIntent,
                        !empty($kbContext) ? ($kbMeta['top_relevance'] ?? 0) : 0.0,
                        $configuredThreshold
                    );

                    if (!$routing['use_agent']) {
                        // Simple + high-quality KB → Standard Mode (fast & cheap)
                        $this->sendSSE('agent_routing', [
                            'action' => 'skip_agent',
                            'reason' => $routing['reason'],
                            'complexity_score' => $routing['complexity_score'],
                            'kb_top_relevance' => $routing['kb_top_relevance'],
                        ]);
                        $chatResponse = $this->runChatModel($bot, $flow, $message, $conversationHistory, $kbContext, $apiKey, $memoryNotes);
                        $this->runSecondAI($flow, $chatResponse, $message, $apiKey, $kbContext);
                    } else {
                        // Complex / tool needed / low KB → Agent Loop
                        $this->sendSSE('agent_routing', [
                            'action' => 'full_agent',
                            'reason' => $routing['reason'],
                            'complexity_score' => $routing['complexity_score'],
                            'kb_top_relevance' => $routing['kb_top_relevance'],
                            'tool_intent' => $routing['tool_intent'],
                        ]);
                        $this->runAgentLoop($bot, $flow, $message, $conversationHistory, $apiKey, $user, $kbContext, $memoryNotes);
                    }
                } else {
                    // === STANDARD MODE: Decision → KB → Chat → Second AI ===

                    // === STEP 2: Decision Model - Intent Analysis ===
                    $intent = $this->runDecisionModel($bot, $message, $apiKey);

                    // === STEP 3: Knowledge Base Search ===
                    $kbContext = $this->runKnowledgeBaseSearch($bot, $flow, $message, $intent);

                    // === STEP 4: Chat Model - Generate Response ===
                    $chatResponse = $this->runChatModel($bot, $flow, $message, $conversationHistory, $kbContext, $apiKey, $memoryNotes);

                    // === STEP 5: Second AI - Content Verification ===
                    $finalResponse = $this->runSecondAI($flow, $chatResponse, $message, $apiKey);

                    // === SEMANTIC CACHE: Save response ===
                    if ($this->semanticCache?->isEnabled() && !empty($finalResponse)) {
                        rescue(function () use ($bot, $message, $finalResponse) {
                            $this->semanticCache->put($bot, $message, $finalResponse);
                        }, null, report: false);
                    }
                }

                // === STEP 6: Done (if not already sent) ===
                if (!$this->doneSent) {
                    $totalTime = round((microtime(true) - $this->metrics['start_time']) * 1000);
                    $this->sendSSE('done', [
                        'total_time_ms' => $totalTime,
                        'prompt_tokens' => $this->metrics['prompt_tokens'],
                        'completion_tokens' => $this->metrics['completion_tokens'],
                        'models_used' => $this->metrics['models_used'],
                        'tool_calls' => $this->metrics['tool_calls'],
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('Stream error', [
                    'bot_id' => $bot->id,
                    'flow_id' => $flow->id,
                    'error' => $e->getMessage(),
                ]);
                $this->sendSSE('error', [
                    'message' => $e->getMessage(),
                    'step' => 'unknown',
                ]);
            } finally {
                // ALWAYS send done event if not already sent (ensures frontend never hangs)
                if (!$this->doneSent) {
                    $this->sendSSE('done', [
                        'total_time_ms' => round((microtime(true) - $this->metrics['start_time']) * 1000),
                        'prompt_tokens' => $this->metrics['prompt_tokens'],
                        'completion_tokens' => $this->metrics['completion_tokens'],
                        'models_used' => $this->metrics['models_used'],
                        'tool_calls' => $this->metrics['tool_calls'],
                        'error' => isset($e),
                    ]);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => config('app.frontend_url', '*'),
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Allow-Credentials' => 'true',
        ]);
    }

    /**
     * Step 2: Run Decision Model for Intent Analysis
     */
    protected function runDecisionModel(Bot $bot, string $message, ?string $apiKey): array
    {
        $decisionModel = $bot->decision_model;

        // Skip if no decision model configured
        if (empty($decisionModel)) {
            $this->sendSSE('decision_skip', [
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
        $this->sendSSE('decision_start', [
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
        if (!empty($result['model_used'])) {
            $this->metrics['models_used'][] = $result['model_used'];
        }
        if (!empty($result['usage'])) {
            $this->metrics['prompt_tokens'] += $result['usage']['prompt_tokens'] ?? 0;
            $this->metrics['completion_tokens'] += $result['usage']['completion_tokens'] ?? 0;
        }

        // Check if it was skipped (no decision model)
        if (!empty($result['skipped'])) {
            return $result;
        }

        // Check if there was an error
        if (!empty($result['error'])) {
            $this->sendSSE('decision_fallback', [
                'original_model' => $decisionModel,
                'fallback_model' => $bot->fallback_decision_model,
                'error' => $result['error'],
            ]);
        }

        $this->sendSSE('decision_result', [
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
    protected function runKnowledgeBaseSearch(Bot $bot, Flow $flow, string $message, array $intent): string
    {
        // Check if we should use KB
        $shouldUseKB = ($intent['intent'] === 'knowledge' || isset($intent['skipped']))
            && $this->intentAnalysis->shouldUseKnowledgeBase($bot);

        // Also check flow-level KBs
        $flowKBs = $flow->knowledgeBases;
        $hasFlowKBs = $flowKBs && $flowKBs->isNotEmpty();

        if (!$shouldUseKB && !$hasFlowKBs) {
            $this->sendSSE('kb_skip', [
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

        $this->sendSSE('kb_search', [
            'knowledge_bases' => $kbList,
            'query' => substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''),
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

            $this->sendSSE('kb_result', [
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

            $this->sendSSE('kb_result', [
                'results' => [],
                'total_chunks' => 0,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Run KB search for Agentic Mode (pre-search, no Decision Model needed).
     * Always searches if flow has KBs attached. Returns formatted context or empty string.
     */
    protected function runAgentKnowledgeBaseSearch(Bot $bot, Flow $flow, string $message): string
    {
        $flowKBs = $flow->knowledgeBases;

        if (!$flowKBs || $flowKBs->isEmpty()) {
            return '';
        }

        $startTime = microtime(true);

        $kbList = $flowKBs->map(fn ($kb) => ['id' => $kb->id, 'name' => $kb->name])->toArray();

        $this->sendSSE('kb_search', [
            'knowledge_bases' => $kbList,
            'query' => substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''),
            'source' => 'agent_pre_search',
        ]);

        try {
            $embeddingApiKey = $bot->user?->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

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

            $kbResults = [];
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

            $this->lastKbSearchMeta = [
                'total_chunks' => $results->count(),
                'top_relevance' => $results->isNotEmpty() ? $results->max('similarity') : 0,
                'avg_relevance' => $results->isNotEmpty() ? round($results->avg('similarity'), 3) : 0,
            ];

            $timeMs = round((microtime(true) - $startTime) * 1000);

            $this->sendSSE('kb_result', [
                'results' => $kbResults,
                'total_chunks' => $results->count(),
                'search_mode' => $this->hybridSearch->isEnabled() ? 'hybrid' : 'semantic',
                'time_ms' => $timeMs,
                'source' => 'agent_pre_search',
            ]);

            if ($results->isEmpty()) {
                $this->lastKbSearchMeta = ['total_chunks' => 0, 'top_relevance' => 0, 'avg_relevance' => 0];
                return '';
            }

            return $this->ragService->formatKnowledgeBaseContext($results);

        } catch (\Exception $e) {
            Log::error('Agent KB pre-search failed', [
                'bot_id' => $bot->id,
                'flow_id' => $flow->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendSSE('kb_result', [
                'results' => [],
                'total_chunks' => 0,
                'error' => $e->getMessage(),
                'source' => 'agent_pre_search',
            ]);

            return '';
        }
    }

    /**
     * Step 4: Run Chat Model for Response Generation (with streaming)
     *
     * @return string The collected response content for Second AI processing
     */
    protected function runChatModel(
        Bot $bot,
        Flow $flow,
        string $message,
        array $conversationHistory,
        string $kbContext,
        ?string $apiKey,
        array $memoryNotes = []
    ): string {
        // Get models from Bot Settings
        $chatModel = $this->getChatModel($bot);
        $fallbackChatModel = $this->getFallbackChatModel($bot);

        // Build system prompt with memory prefix (same format as RAGService)
        $systemPrompt = $this->buildMemoryPrefix($memoryNotes)
            . ($flow->system_prompt ?: $this->getDefaultSystemPrompt($bot));
        if (!empty($kbContext)) {
            $systemPrompt .= "\n\n" . $kbContext;
        }

        $this->sendSSE('chat_start', [
            'model' => $chatModel,
            'system_prompt_length' => strlen($systemPrompt),
            'has_kb_context' => !empty($kbContext),
        ]);

        // Build messages array
        $messages = $this->buildMessages($systemPrompt, $conversationHistory, $message);

        $startTime = microtime(true);
        $usedFallback = false;
        $collectedResponse = '';

        try {
            $collectedResponse = $this->streamFromOpenRouter($messages, $chatModel, $apiKey, $flow->temperature, $flow->max_tokens);
            $this->metrics['models_used'][] = $chatModel;
        } catch (\Exception $e) {
            // Try fallback model
            if ($fallbackChatModel) {
                $this->sendSSE('chat_fallback', [
                    'original_model' => $chatModel,
                    'fallback_model' => $fallbackChatModel,
                    'error' => $e->getMessage(),
                ]);

                try {
                    $collectedResponse = $this->streamFromOpenRouter($messages, $fallbackChatModel, $apiKey, $flow->temperature, $flow->max_tokens);
                    $this->metrics['models_used'][] = $fallbackChatModel;
                    $usedFallback = true;
                } catch (\Exception $fallbackError) {
                    throw new \Exception('Both primary and fallback models failed: ' . $fallbackError->getMessage());
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
    protected function streamFromOpenRouter(array $messages, string $model, ?string $apiKey, ?float $temperature = null, ?int $maxTokens = null): string
    {
        $client = new Client([
            'timeout' => (int) config('services.openrouter.stream_timeout', 120),
            'connect_timeout' => (int) config('services.openrouter.connect_timeout', 10),
        ]);

        $baseUrl = $this->openRouterBaseUrl;
        $apiKey = $apiKey ?: config('services.openrouter.api_key');

        $response = $client->post($baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
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

        while (!$body->eof()) {
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
                        $this->sendSSE('content', ['text' => $content]);
                    }

                    // Track usage if available
                    if ($json && isset($json['usage'])) {
                        $this->metrics['prompt_tokens'] += $json['usage']['prompt_tokens'] ?? 0;
                        $this->metrics['completion_tokens'] += $json['usage']['completion_tokens'] ?? 0;
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

    /**
     * Step 5: Run Second AI for Content Verification
     *
     * Executes Second AI checks (Fact Check, Policy, Personality) if enabled.
     * Sends SSE events for timeline display in Chat Emulator.
     *
     * @param Flow $flow The flow with second_ai configuration
     * @param string $originalResponse The original AI response to check
     * @param string $userMessage The original user message (for context)
     * @param string|null $apiKey Optional API key override
     * @return string The final response (modified or original)
     */
    protected function runSecondAI(
        Flow $flow,
        string $originalResponse,
        string $userMessage,
        ?string $apiKey,
        string $kbContext = ''
    ): string {
        // Check if client disconnected before starting
        if (connection_aborted()) {
            Log::info('SecondAI: Client disconnected before starting');
            return $originalResponse;
        }

        // Skip if Second AI is not enabled or response is empty
        if (!$flow->second_ai_enabled || empty($originalResponse)) {
            return $originalResponse;
        }

        $options = $flow->second_ai_options ?? [];
        $enabledChecks = [];

        if (!empty($options['fact_check'])) {
            $enabledChecks[] = 'fact_check';
        }
        if (!empty($options['policy'])) {
            $enabledChecks[] = 'policy';
        }
        if (!empty($options['personality'])) {
            $enabledChecks[] = 'personality';
        }

        // Skip if no checks are enabled
        if (empty($enabledChecks)) {
            return $originalResponse;
        }

        $startTime = microtime(true);

        // Get model from Bot settings (same logic as UnifiedCheckService)
        $bot = $flow->bot;
        $model = $bot?->decision_model ?: $bot?->primary_chat_model ?: 'openai/gpt-4o-mini';

        // Send start event
        $this->sendSSE('second_ai_start', [
            'enabled_checks' => $enabledChecks,
            'model' => $model,
        ]);

        // Set tight timeout for Second AI in streaming context
        $this->secondAI->setTimeout(
            (int) config('rag.second_ai.pipeline_timeout', 8)
        );

        // Use rescue() for graceful timeout handling
        $result = rescue(
            function () use ($originalResponse, $flow, $userMessage, $apiKey, $kbContext) {
                return $this->secondAI->process(
                    $originalResponse,
                    $flow,
                    $userMessage,
                    $apiKey,
                    $kbContext
                );
            },
            function (\Throwable $e) use ($originalResponse, $flow) {
                Log::error('SecondAI: Process failed or timed out', [
                    'flow_id' => $flow->id,
                    'error' => $e->getMessage(),
                    ...(!app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
                ]);
                // Also log to stderr for Railway visibility
                error_log('SecondAI ERROR: ' . $e->getMessage());
                return [
                    'content' => $originalResponse,
                    'second_ai_applied' => false,
                    'second_ai' => ['error' => 'timeout_or_error'],
                ];
            },
            report: false
        );

        $timeMs = round((microtime(true) - $startTime) * 1000);

        // Determine if checks passed (no modifications applied)
        $passed = !($result['second_ai_applied'] ?? false);
        $checksApplied = $result['second_ai']['checks_applied'] ?? [];
        $modifications = $result['second_ai']['modifications'] ?? [];
        $error = $result['second_ai']['error'] ?? null;

        // Send result event (always sent, success or failure)
        $this->sendSSE('second_ai_result', [
            'passed' => $passed,
            'checks_applied' => $checksApplied,
            'modifications' => $modifications,
            'time_ms' => $timeMs,
            ...($error ? ['error' => $error] : []),
        ]);

        // If content was modified, send the modified content event
        $finalContent = $result['content'] ?? $originalResponse;
        if (!$passed && $finalContent !== $originalResponse && empty($error)) {
            $this->sendSSE('second_ai_modified', [
                'content' => $finalContent,
                'original_length' => strlen($originalResponse),
                'modified_length' => strlen($finalContent),
            ]);
        }

        return $finalContent;
    }

    // =====================
    // Helper Methods
    // =====================

    protected function authenticateFromToken(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return null;
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }

        return $accessToken->tokenable;
    }

    protected function getChatModel(Bot $bot): string
    {
        return $bot->primary_chat_model
            ?: $bot->fallback_chat_model
            ?: config('services.openrouter.default_model', 'anthropic/claude-3.5-sonnet');
    }

    protected function getFallbackChatModel(Bot $bot): ?string
    {
        return $bot->fallback_chat_model
            ?: config('services.openrouter.fallback_model');
    }

    protected function buildMessages(string $systemPrompt, array $history, string $message): array
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

            if (!empty($content) && in_array($role, ['user', 'assistant'])) {
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
    protected function buildMemoryPrefix(array $memoryNotes): string
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

    protected function getDefaultSystemPrompt(Bot $bot): string
    {
        return <<<PROMPT
You are a helpful AI assistant for {$bot->name}.
Be friendly, professional, and helpful.
Respond in the same language as the user's message.
If you don't know something, be honest about it.
Keep responses concise but informative.
PROMPT;
    }

    /**
     * Run Agent Loop with tool calling (Agentic Mode).
     * Delegates to AgentLoopService with SSE callbacks.
     */
    protected function runAgentLoop(
        Bot $bot,
        Flow $flow,
        string $message,
        array $conversationHistory,
        string $apiKey,
        ?User $user = null,
        string $kbContext = '',
        array $memoryNotes = []
    ): void {
        $config = new AgentLoopConfig(
            bot: $bot,
            flow: $flow,
            userMessage: $message,
            conversationHistory: $conversationHistory,
            apiKey: $apiKey,
            userId: $user?->id,
            kbContext: $kbContext,
            memoryNotes: $memoryNotes,
        );

        $callbacks = new SseAgentCallbacks(
            fn(string $event, array $data) => $this->sendSSE($event, $data),
            $this->metrics,
        );

        $result = $this->agentLoopService->run($config, $callbacks);

        // Update metrics from result
        $this->metrics['models_used'][] = $result->model;
        $this->metrics['prompt_tokens'] += $result->usage['prompt_tokens'] ?? 0;
        $this->metrics['completion_tokens'] += $result->usage['completion_tokens'] ?? 0;

        // === Second AI - Content Verification for Agentic Mode ===
        if (!empty($result->content)) {
            $this->runSecondAI($flow, $result->content, $message, $apiKey, $kbContext);
        }
    }

    /**
     * Send SSE event to client.
     *
     * @param string $event Event name
     * @param array $data Event data
     * @return bool True if event was sent successfully, false if connection was aborted
     */
    protected function sendSSE(string $event, array $data): bool
    {
        // Check connection before sending (don't exit, just return false)
        if (connection_aborted()) {
            return false;
        }

        // Track done event
        if ($event === 'done') {
            $this->doneSent = true;
        }

        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        return true;
    }

    protected function errorResponse(string $message, int $status): StreamedResponse
    {
        return new StreamedResponse(function () use ($message) {
            $this->sendSSE('error', ['message' => $message]);
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => config('app.frontend_url', '*'),
        ]);
    }
}
