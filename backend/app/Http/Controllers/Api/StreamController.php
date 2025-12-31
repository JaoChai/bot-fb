<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Flow;
use App\Models\User;
use App\Services\AgentSafetyService;
use App\Services\CostTrackingService;
use App\Services\HybridSearchService;
use App\Services\OpenRouterService;
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
        AgentSafetyService $agentSafety
    ) {
        $this->openRouter = $openRouter;
        $this->hybridSearch = $hybridSearch;
        $this->toolService = $toolService;
        $this->costTracking = $costTracking;
        $this->agentSafety = $agentSafety;
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
        return new StreamedResponse(function () use ($bot, $flow, $message, $conversationHistory, $apiKey) {
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

                // === AGENTIC MODE: Use Agent Loop ===
                if ($flow->agentic_mode && !empty($flow->enabled_tools)) {
                    $this->runAgentLoop($bot, $flow, $message, $conversationHistory, $apiKey, $user);
                } else {
                    // === STANDARD MODE: Decision → KB → Chat ===

                    // === STEP 2: Decision Model - Intent Analysis ===
                    $intent = $this->runDecisionModel($bot, $message, $apiKey);

                    // === STEP 3: Knowledge Base Search ===
                    $kbContext = $this->runKnowledgeBaseSearch($bot, $flow, $message, $intent);

                    // === STEP 4: Chat Model - Generate Response ===
                    $this->runChatModel($bot, $flow, $message, $conversationHistory, $kbContext, $apiKey);
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
        $fallbackDecisionModel = $bot->fallback_decision_model;

        // Skip if no decision model configured
        if (empty($decisionModel)) {
            $this->sendSSE('decision_skip', [
                'reason' => 'ไม่ได้ตั้งค่า Decision Model',
            ]);

            // Default: use KB if enabled, otherwise chat
            return [
                'intent' => $this->shouldUseKnowledgeBase($bot) ? 'knowledge' : 'chat',
                'confidence' => 1.0,
                'skipped' => true,
            ];
        }

        $startTime = microtime(true);
        $this->sendSSE('decision_start', [
            'model' => $decisionModel,
        ]);

        try {
            $prompt = $this->buildIntentAnalysisPrompt($bot);

            $result = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user', 'content' => $message],
                ],
                model: $decisionModel,
                temperature: 0.1,
                maxTokens: 150,
                useFallback: true,
                apiKeyOverride: $apiKey,
                fallbackModelOverride: $fallbackDecisionModel
            );

            $rawContent = $result['content'] ?? '';
            Log::debug('Decision Model Raw Response', [
                'bot_id' => $bot->id,
                'model' => $decisionModel,
                'raw_content' => $rawContent,
            ]);

            $parsed = $this->parseIntentResponse($rawContent);
            $timeMs = round((microtime(true) - $startTime) * 1000);

            $this->metrics['models_used'][] = $result['model'] ?? $decisionModel;
            $this->metrics['prompt_tokens'] += $result['usage']['prompt_tokens'] ?? 0;
            $this->metrics['completion_tokens'] += $result['usage']['completion_tokens'] ?? 0;

            $this->sendSSE('decision_result', [
                'intent' => $parsed['intent'],
                'confidence' => $parsed['confidence'],
                'model' => $result['model'] ?? $decisionModel,
                'tokens' => ($result['usage']['prompt_tokens'] ?? 0) + ($result['usage']['completion_tokens'] ?? 0),
                'time_ms' => $timeMs,
            ]);

            return $parsed;

        } catch (\Exception $e) {
            Log::warning('Decision model failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            // Send fallback event if we used fallback
            if ($fallbackDecisionModel) {
                $this->sendSSE('decision_fallback', [
                    'original_model' => $decisionModel,
                    'fallback_model' => $fallbackDecisionModel,
                    'error' => $e->getMessage(),
                ]);
            }

            // Default to knowledge if KB enabled, otherwise chat
            return [
                'intent' => $this->shouldUseKnowledgeBase($bot) ? 'knowledge' : 'chat',
                'confidence' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Step 3: Run Knowledge Base Search
     */
    protected function runKnowledgeBaseSearch(Bot $bot, Flow $flow, string $message, array $intent): string
    {
        // Check if we should use KB
        $shouldUseKB = ($intent['intent'] === 'knowledge' || isset($intent['skipped']))
            && $this->shouldUseKnowledgeBase($bot);

        // Also check flow-level KBs
        $flowKBs = $flow->knowledgeBases;
        $hasFlowKBs = $flowKBs && $flowKBs->isNotEmpty();

        if (!$shouldUseKB && !$hasFlowKBs) {
            $this->sendSSE('kb_skip', [
                'reason' => $this->shouldUseKnowledgeBase($bot)
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

            // Format context for prompt
            return $this->formatKnowledgeBaseContext($results);

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
     */
    protected function runChatModel(
        Bot $bot,
        Flow $flow,
        string $message,
        array $conversationHistory,
        string $kbContext,
        ?string $apiKey
    ): void {
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

        try {
            $this->streamFromOpenRouter($messages, $chatModel, $apiKey);
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
                    $this->streamFromOpenRouter($messages, $fallbackChatModel, $apiKey);
                    $this->metrics['models_used'][] = $fallbackChatModel;
                    $usedFallback = true;
                } catch (\Exception $fallbackError) {
                    throw new \Exception('Both primary and fallback models failed: ' . $fallbackError->getMessage());
                }
            } else {
                throw $e;
            }
        }
    }

    /**
     * Stream response from OpenRouter using Guzzle with true streaming.
     */
    protected function streamFromOpenRouter(array $messages, string $model, ?string $apiKey): void
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
                        return;
                    }

                    $json = json_decode($data, true);
                    if ($json && isset($json['choices'][0]['delta']['content'])) {
                        $content = $json['choices'][0]['delta']['content'];
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
                return;
            }
        }
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
            ?: $bot->llm_model
            ?: config('services.openrouter.default_model', 'anthropic/claude-3.5-sonnet');
    }

    protected function getFallbackChatModel(Bot $bot): ?string
    {
        return $bot->fallback_chat_model
            ?: $bot->llm_fallback_model
            ?: config('services.openrouter.fallback_model');
    }

    protected function shouldUseKnowledgeBase(Bot $bot): bool
    {
        if (!$bot->kb_enabled) {
            return false;
        }
        return $bot->knowledgeBase !== null;
    }

    protected function buildIntentAnalysisPrompt(Bot $bot): string
    {
        $hasKB = $bot->kb_enabled && $bot->knowledgeBase;
        $kbNote = $hasKB ? ' (Knowledge Base available for factual queries)' : '';

        return <<<PROMPT
You are an intent classifier. Analyze the user's message and determine the appropriate intent.
Respond with JSON only: {"intent": "chat|knowledge", "confidence": 0.0-1.0}

Available intents:
- "chat": General conversation, greetings, opinions, casual talk, or when unsure
- "knowledge": Questions requiring factual information, specific data, or documentation{$kbNote}

Classification rules:
- Use "knowledge" for: questions about facts, how-to queries, data lookups, technical questions
- Use "chat" for: greetings (hi, hello), opinions, casual conversation, follow-up responses
- When uncertain, prefer "chat" (safer default)

Respond with JSON only, no explanation.
PROMPT;
    }

    protected function parseIntentResponse(string $content): array
    {
        $content = trim($content);

        // Remove markdown code blocks (greedy to capture full JSON)
        if (preg_match('/```(?:json)?\s*(\{.+\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        // Extract JSON object with proper brace matching
        $content = $this->extractJsonObject($content);

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $intent = $data['intent'] ?? 'chat';
            $confidence = (float) ($data['confidence'] ?? 0.5);

            if (!in_array($intent, ['chat', 'knowledge'])) {
                $intent = 'chat';
            }

            $confidence = max(0, min(1, $confidence));

            return [
                'intent' => $intent,
                'confidence' => $confidence,
            ];
        } catch (\Exception $e) {
            Log::warning('Decision Model JSON Parse Failed', [
                'content' => substr($content, 0, 500),
                'error' => $e->getMessage(),
            ]);
            return [
                'intent' => 'chat',
                'confidence' => 0,
            ];
        }
    }

    /**
     * Extract JSON object from string with proper brace matching.
     * Handles nested objects unlike simple regex.
     */
    protected function extractJsonObject(string $content): string
    {
        $start = strpos($content, '{');
        if ($start === false) {
            return $content;
        }

        $depth = 0;
        $length = strlen($content);
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($char === '\\') {
                $escape = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return $content;
    }

    protected function formatKnowledgeBaseContext($results): string
    {
        if ($results->isEmpty()) {
            return '';
        }

        $context = "## ข้อมูลอ้างอิงจาก Knowledge Base:\n\n";

        foreach ($results as $i => $result) {
            $relevance = round($result['similarity'] * 100);
            $context .= "### แหล่งที่ " . ($i + 1) . " (ความเกี่ยวข้อง {$relevance}%)\n";
            $context .= "📄 {$result['document_name']}\n\n";
            $context .= $result['content'] . "\n\n";
        }

        $context .= "---\n";
        $context .= "📌 **คำแนะนำ**: ใช้ข้อมูลด้านบนในการตอบคำถาม ";
        $context .= "หากข้อมูลไม่เกี่ยวข้องหรือไม่เพียงพอ ให้ตอบตามความรู้ทั่วไปและแจ้งผู้ใช้ว่าไม่พบข้อมูลในระบบ\n";

        return $context;
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
                    // Process each tool call
                    foreach ($response['tool_calls'] as $toolCall) {
                        $this->metrics['tool_calls']++;
                        $this->costTracking->addToolCall();

                        $toolId = $toolCall['id'] ?? 'tool_' . $iteration;
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

                            // Wait for approval (blocking)
                            $approval = $this->agentSafety->waitForApproval($approvalId, 60);

                            $this->sendSSE('agent_approval_response', [
                                'approval_id' => $approvalId,
                                'approved' => $approval['approved'],
                                'reason' => $approval['reason'] ?? null,
                            ]);

                            if (!$approval['approved']) {
                                // Skip this tool call
                                $messages[] = [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'tool_calls' => [$toolCall],
                                ];
                                $messages[] = [
                                    'role' => 'tool',
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

                        // Add assistant message with tool call
                        $messages[] = [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [$toolCall],
                        ];

                        // Add tool result message
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolId,
                            'content' => $toolResult['status'] === 'success'
                                ? ($toolResult['result'] ?? '')
                                : ('Error: ' . ($toolResult['error'] ?? 'Unknown error')),
                        ];
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
