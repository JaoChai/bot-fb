<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Flow;
use App\Models\User;
use App\Services\AgentSafetyService;
use App\Services\CostTrackingService;
use App\Services\HybridSearchService;
use App\Services\IntentAnalysisService;
use App\Services\OpenRouterService;
use App\Services\RAGService;
use App\Services\SecondAI\SecondAIService;
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

    // Track process metrics
    protected array $metrics = [
        'start_time' => 0,
        'models_used' => [],
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'tool_calls' => 0,
    ];

    public function __construct(
        OpenRouterService $openRouter,
        HybridSearchService $hybridSearch,
        ToolService $toolService,
        CostTrackingService $costTracking,
        AgentSafetyService $agentSafety,
        SecondAIService $secondAI,
        IntentAnalysisService $intentAnalysis,
        RAGService $ragService
    ) {
        $this->openRouter = $openRouter;
        $this->hybridSearch = $hybridSearch;
        $this->toolService = $toolService;
        $this->costTracking = $costTracking;
        $this->agentSafety = $agentSafety;
        $this->secondAI = $secondAI;
        $this->intentAnalysis = $intentAnalysis;
        $this->ragService = $ragService;
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

        // 5. Create SSE response
        return new StreamedResponse(function () use ($bot, $flow, $message, $conversationHistory, $apiKey, $user) {
            // Disable output buffering for streaming
            while (ob_get_level()) {
                ob_end_clean();
            }

            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
            @ini_set('implicit_flush', true);

            $this->metrics['start_time'] = microtime(true);

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

                        // Send done event and exit
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

                // === AGENTIC MODE: Use Agent Loop ===
                if ($flow->agentic_mode && !empty($flow->enabled_tools)) {
                    $this->runAgentLoop($bot, $flow, $message, $conversationHistory, $apiKey, $user);
                } else {
                    // === STANDARD MODE: Decision → KB → Chat → Second AI ===

                    // === STEP 2: Decision Model - Intent Analysis ===
                    $intent = $this->runDecisionModel($bot, $message, $apiKey);

                    // === STEP 3: Knowledge Base Search ===
                    $kbContext = $this->runKnowledgeBaseSearch($bot, $flow, $message, $intent);

                    // === STEP 4: Chat Model - Generate Response ===
                    $chatResponse = $this->runChatModel($bot, $flow, $message, $conversationHistory, $kbContext, $apiKey);

                    // === STEP 5: Second AI - Content Verification ===
                    $this->runSecondAI($flow, $chatResponse, $message, $apiKey);
                }

                // === STEP 5: Done ===
                $totalTime = round((microtime(true) - $this->metrics['start_time']) * 1000);
                $this->sendSSE('done', [
                    'total_time_ms' => $totalTime,
                    'prompt_tokens' => $this->metrics['prompt_tokens'],
                    'completion_tokens' => $this->metrics['completion_tokens'],
                    'models_used' => $this->metrics['models_used'],
                    'tool_calls' => $this->metrics['tool_calls'],
                ]);

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

                // Always send done event even on error
                $this->sendSSE('done', [
                    'total_time_ms' => round((microtime(true) - $this->metrics['start_time']) * 1000),
                    'prompt_tokens' => $this->metrics['prompt_tokens'],
                    'completion_tokens' => $this->metrics['completion_tokens'],
                    'models_used' => $this->metrics['models_used'],
                    'tool_calls' => $this->metrics['tool_calls'],
                    'error' => true,
                ]);
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
        ?string $apiKey
    ): string {
        // Get models from Bot Settings
        $chatModel = $this->getChatModel($bot);
        $fallbackChatModel = $this->getFallbackChatModel($bot);

        // Build system prompt
        $systemPrompt = $flow->system_prompt ?: $this->getDefaultSystemPrompt($bot);
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
            $collectedResponse = $this->streamFromOpenRouter($messages, $chatModel, $apiKey);
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
                    $collectedResponse = $this->streamFromOpenRouter($messages, $fallbackChatModel, $apiKey);
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
    protected function streamFromOpenRouter(array $messages, string $model, ?string $apiKey): string
    {
        $client = new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);

        $baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $apiKey = $apiKey ?: config('services.openrouter.api_key');

        $response = $client->post($baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('services.openrouter.site_url', config('app.url')),
                'X-Title' => config('services.openrouter.site_name', config('app.name')),
            ],
            'json' => [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
                'temperature' => 0.7,
                'max_tokens' => 4096,
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
        ?string $apiKey
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

        // Use rescue() for graceful timeout handling
        $result = rescue(
            function () use ($originalResponse, $flow, $userMessage, $apiKey) {
                return $this->secondAI->process(
                    $originalResponse,
                    $flow,
                    $userMessage,
                    $apiKey
                );
            },
            function (\Throwable $e) use ($originalResponse, $flow) {
                Log::error('SecondAI: Process failed or timed out', [
                    'flow_id' => $flow->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
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
     *
     * Implements ReAct pattern: Think → Act → Observe → Repeat
     * Includes safety mechanisms: timeout, cost limits, HITL approval
     */
    protected function runAgentLoop(
        Bot $bot,
        Flow $flow,
        string $message,
        array $conversationHistory,
        string $apiKey,
        ?User $user = null
    ): void {
        $maxIterations = $flow->max_tool_calls ?? 10;
        $tools = $this->toolService->getToolDefinitions($flow->enabled_tools ?? []);
        $chatModel = $this->getChatModel($bot);

        // === SAFETY: Initialize tracking ===
        $requestId = $this->costTracking->startRequest();
        $loopStartTime = microtime(true);
        $safetyConfig = $this->agentSafety->getSafetyConfig($flow);

        // Build initial messages
        $systemPrompt = $this->buildAgentSystemPrompt($bot, $flow);
        $messages = $this->buildMessages($systemPrompt, $conversationHistory, $message);

        $this->sendSSE('agent_start', [
            'max_iterations' => $maxIterations,
            'tools' => array_map(fn($t) => $t['function']['name'] ?? 'unknown', $tools),
            'model' => $chatModel,
            'safety' => [
                'timeout_seconds' => $safetyConfig['timeout_seconds'],
                'max_cost' => $safetyConfig['max_cost_per_request'],
                'hitl_enabled' => $safetyConfig['hitl_enabled'],
            ],
        ]);

        $iteration = 0;
        $finalStatus = 'completed';
        $errorMessage = null;

        while ($iteration < $maxIterations) {
            $iteration++;

            // === SAFETY: Check timeout ===
            $safetyViolation = $this->agentSafety->checkLimits(
                $flow,
                $loopStartTime,
                $this->costTracking->getRunningCost(),
                $user?->id ?? 0
            );

            if ($safetyViolation) {
                $this->sendSSE('agent_safety_stop', [
                    'type' => $safetyViolation['type'],
                    'details' => $safetyViolation,
                    'iteration' => $iteration,
                ]);

                $finalStatus = $safetyViolation['type'] === 'timeout' ? 'timeout' : 'cost_limit';
                break;
            }

            // === SAFETY: Check daily cost limit ===
            if ($user && $this->costTracking->exceedsDailyLimit($user)) {
                $this->sendSSE('agent_safety_stop', [
                    'type' => 'daily_limit',
                    'message' => 'ถึงวงเงินรายวันแล้ว',
                    'iteration' => $iteration,
                ]);

                $finalStatus = 'cost_limit';
                break;
            }

            $this->sendSSE('agent_thinking', [
                'iteration' => $iteration,
                'elapsed_seconds' => round(microtime(true) - $loopStartTime, 1),
                'running_cost' => round($this->costTracking->getRunningCost(), 6),
            ]);

            try {
                // Truncate messages if approaching token limit to prevent memory growth
                $messages = $this->truncateMessagesIfNeeded($messages);

                // Call LLM with tools
                $response = $this->openRouter->chatWithTools(
                    messages: $messages,
                    tools: $tools,
                    model: $chatModel,
                    temperature: $flow->temperature ?? 0.7,
                    maxTokens: $flow->max_tokens ?? 4096,
                    apiKeyOverride: $apiKey,
                    toolChoice: 'auto'
                );

                // === SAFETY: Track cost ===
                $promptTokens = $response['usage']['prompt_tokens'] ?? 0;
                $completionTokens = $response['usage']['completion_tokens'] ?? 0;
                $this->costTracking->addCost($chatModel, $promptTokens, $completionTokens);

                $this->metrics['models_used'][] = $response['model'] ?? $chatModel;
                $this->metrics['prompt_tokens'] += $promptTokens;
                $this->metrics['completion_tokens'] += $completionTokens;

                $finishReason = $response['finish_reason'] ?? 'stop';

                // Check if we should execute tools
                if ($finishReason === 'tool_calls' && !empty($response['tool_calls'])) {
                    // Collect all tool calls and results for proper batching (OpenAI spec)
                    $processedToolCalls = [];
                    $toolResults = [];

                    // Process each tool call
                    foreach ($response['tool_calls'] as $toolCall) {
                        $this->metrics['tool_calls']++;
                        $this->costTracking->addToolCall();

                        $toolId = $toolCall['id'] ?? 'tool_' . $iteration . '_' . count($processedToolCalls);
                        $toolName = $toolCall['function']['name'] ?? 'unknown';
                        $toolArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];

                        // === SAFETY: Check HITL approval for dangerous actions ===
                        if ($this->agentSafety->requiresApproval($flow, $toolName, $toolArgs)) {
                            $approvalId = $this->agentSafety->requestApproval(
                                $requestId,
                                $flow,
                                $toolName,
                                $toolArgs,
                                60 // 60 second timeout
                            );

                            $this->sendSSE('agent_approval_required', [
                                'approval_id' => $approvalId,
                                'tool_name' => $toolName,
                                'tool_args' => $toolArgs,
                                'timeout_seconds' => 60,
                            ]);

                            // Wait for approval with heartbeat to keep SSE alive
                            $approval = $this->agentSafety->waitForApproval(
                                $approvalId,
                                60,
                                fn($elapsed, $timeout) => $this->sendSSE('agent_approval_waiting', [
                                    'approval_id' => $approvalId,
                                    'elapsed_seconds' => $elapsed,
                                    'timeout_seconds' => $timeout,
                                    'tool_name' => $toolName,
                                ])
                            );

                            $this->sendSSE('agent_approval_response', [
                                'approval_id' => $approvalId,
                                'approved' => $approval['approved'],
                                'reason' => $approval['reason'] ?? null,
                            ]);

                            if (!$approval['approved']) {
                                // Mark as rejected - still add to batch
                                $processedToolCalls[] = $toolCall;
                                $toolResults[] = [
                                    'tool_call_id' => $toolId,
                                    'content' => 'Action was rejected by user: ' . ($approval['reason'] ?? 'No reason provided'),
                                ];
                                continue;
                            }
                        }

                        // Send tool call event
                        $this->sendSSE('tool_call', [
                            'iteration' => $iteration,
                            'tool_id' => $toolId,
                            'tool_name' => $toolName,
                            'arguments' => $toolArgs,
                        ]);

                        // Execute tool
                        $toolResult = $this->toolService->executeTool(
                            $toolName,
                            $toolArgs,
                            ['flow' => $flow, 'bot' => $bot]
                        );

                        // Send tool result event
                        $this->sendSSE('tool_result', [
                            'iteration' => $iteration,
                            'tool_id' => $toolId,
                            'tool_name' => $toolName,
                            'status' => $toolResult['status'],
                            'result_preview' => $this->truncateForPreview($toolResult['result'] ?? $toolResult['error'] ?? ''),
                            'time_ms' => $toolResult['time_ms'] ?? 0,
                        ]);

                        // Collect for batching
                        $processedToolCalls[] = $toolCall;
                        $toolResults[] = [
                            'tool_call_id' => $toolId,
                            'content' => $toolResult['status'] === 'success'
                                ? ($toolResult['result'] ?? '')
                                : ('Error: ' . ($toolResult['error'] ?? 'Unknown error')),
                        ];
                    }

                    // Add ONE assistant message with ALL tool calls (OpenAI spec compliance)
                    if (!empty($processedToolCalls)) {
                        $messages[] = [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => $processedToolCalls,
                        ];

                        // Add all tool results
                        foreach ($toolResults as $result) {
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $result['tool_call_id'],
                                'content' => $result['content'],
                            ];
                        }
                    }

                    // Continue loop to process tool results
                    continue;
                }

                // No more tool calls - generate final response
                $this->sendSSE('agent_done', [
                    'iterations' => $iteration,
                    'total_tool_calls' => $this->metrics['tool_calls'],
                    'total_cost' => round($this->costTracking->getRunningCost(), 6),
                    'elapsed_seconds' => round(microtime(true) - $loopStartTime, 1),
                ]);

                // Stream final response if available
                if (!empty($response['content'])) {
                    $this->sendSSE('chat_start', [
                        'model' => $response['model'] ?? $chatModel,
                        'source' => 'agent_final_response',
                    ]);

                    // Send content in chunks to simulate streaming
                    $content = $response['content'];
                    $chunkSize = 50;
                    $offset = 0;

                    while ($offset < mb_strlen($content)) {
                        $chunk = mb_substr($content, $offset, $chunkSize);
                        $this->sendSSE('content', ['text' => $chunk]);
                        $offset += $chunkSize;
                        usleep(10000); // 10ms delay for smooth streaming effect
                    }

                    // === Second AI - Content Verification for Agentic Mode ===
                    $this->runSecondAI($flow, $content, $message, $apiKey);
                }

                break; // Exit loop - we're done

            } catch (\Exception $e) {
                Log::error('Agent loop error', [
                    'iteration' => $iteration,
                    'error' => $e->getMessage(),
                ]);

                $this->sendSSE('agent_error', [
                    'iteration' => $iteration,
                    'error' => $e->getMessage(),
                ]);

                // Try to generate a response without tools
                $this->sendSSE('agent_fallback', [
                    'reason' => 'Tool calling failed, generating direct response',
                ]);

                $finalStatus = 'error';
                $errorMessage = $e->getMessage();

                // Fall back to regular chat
                $fallbackMessages = $this->buildMessages(
                    $this->getDefaultSystemPrompt($bot),
                    $conversationHistory,
                    $message
                );
                $this->streamFromOpenRouter($fallbackMessages, $chatModel, $apiKey);
                break;
            }
        }

        // Max iterations reached
        if ($iteration >= $maxIterations) {
            $this->sendSSE('agent_max_iterations', [
                'iterations' => $iteration,
                'message' => 'ถึงจำนวนรอบสูงสุดแล้ว',
            ]);
        }

        // === SAFETY: Finalize cost tracking ===
        $durationMs = round((microtime(true) - $loopStartTime) * 1000);

        if ($user) {
            $this->costTracking->finalizeRequest(
                userId: $user->id,
                botId: $bot->id,
                flowId: $flow->id,
                status: $finalStatus,
                durationMs: $durationMs,
                iterations: $iteration,
                modelUsed: $chatModel,
                errorMessage: $errorMessage
            );
        }
    }

    /**
     * Build system prompt for agent mode.
     */
    protected function buildAgentSystemPrompt(Bot $bot, Flow $flow): string
    {
        $basePrompt = $flow->system_prompt ?: $this->getDefaultSystemPrompt($bot);

        $toolsInfo = '';
        $enabledTools = $flow->enabled_tools ?? [];

        if (in_array('search_kb', $enabledTools)) {
            $toolsInfo .= "\n- search_knowledge_base: ใช้เมื่อต้องการค้นหาข้อมูลในฐานความรู้";
        }
        if (in_array('calculate', $enabledTools)) {
            $toolsInfo .= "\n- calculate: ใช้เมื่อต้องการคำนวณตัวเลข";
        }
        if (in_array('think', $enabledTools)) {
            $toolsInfo .= "\n- think: ใช้เพื่อหยุดคิดและวิเคราะห์ก่อนตอบ";
        }

        if (!empty($toolsInfo)) {
            $basePrompt .= "\n\n## Available Tools:{$toolsInfo}\n\nUse tools when needed to provide accurate information.";
        }

        // Add think tool guidance if enabled
        if (in_array('think', $enabledTools)) {
            $basePrompt .= "\n\n## วิธีใช้ Think Tool:";
            $basePrompt .= "\n- ใช้ think ก่อนตอบคำถามที่ซับซ้อนหรือต้องวิเคราะห์หลายขั้นตอน";
            $basePrompt .= "\n- ใช้ think หลังได้ผลลัพธ์จาก search_knowledge_base เพื่อวิเคราะห์ข้อมูล";
            $basePrompt .= "\n- ใช้ think เพื่อวางแผนขั้นตอนการตอบคำถาม";
            $basePrompt .= "\n- ใช้ think เพื่อตรวจสอบความถูกต้องก่อนให้คำตอบสุดท้าย";
        }

        return $basePrompt;
    }

    /**
     * Truncate text for preview in SSE events.
     */
    protected function truncateForPreview(string $text, int $maxLength = 200): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength) . '...';
    }

    /**
     * Truncate messages array if approaching token limit.
     * Keeps system message + recent messages to prevent memory growth.
     *
     * @param array $messages Current messages array
     * @param int $maxMessages Maximum messages to keep (default 30)
     * @return array Truncated messages array
     */
    protected function truncateMessagesIfNeeded(array $messages, int $maxMessages = 30): array
    {
        // If under limit, return as-is
        if (count($messages) <= $maxMessages) {
            return $messages;
        }

        // Keep system message (first) + last N messages
        $systemMessage = null;
        $otherMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemMessage = $msg;
            } else {
                $otherMessages[] = $msg;
            }
        }

        // Keep last (maxMessages - 1) non-system messages
        $keepCount = $maxMessages - ($systemMessage ? 1 : 0);
        $truncatedMessages = array_slice($otherMessages, -$keepCount);

        // Ensure we don't start with orphaned tool result messages
        // Tool results must follow their corresponding assistant message with tool_calls
        while (!empty($truncatedMessages) && $truncatedMessages[0]['role'] === 'tool') {
            array_shift($truncatedMessages);
        }

        // Rebuild with system message first
        $result = [];
        if ($systemMessage) {
            $result[] = $systemMessage;
        }
        $result = array_merge($result, $truncatedMessages);

        return $result;
    }

    protected function sendSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        if (connection_aborted()) {
            exit;
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
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
