<?php

namespace App\Services\Agent;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\User;
use App\Services\AgentSafetyService;
use App\Services\CostTrackingService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\StockInjectionService;
use App\Services\ToolService;
use Illuminate\Support\Facades\Log;

/**
 * AgentLoopService - Unified agent loop for agentic AI mode.
 *
 * Extracted from StreamController to serve both SSE (frontend) and sync (webhook) paths.
 * Implements ReAct pattern: Think -> Act -> Observe -> Repeat
 * Includes safety mechanisms: timeout, cost limits, HITL approval.
 */
class AgentLoopService
{
    public function __construct(
        protected OpenRouterService $openRouter,
        protected ToolService $toolService,
        protected AgentSafetyService $agentSafety,
        protected CostTrackingService $costTracking,
        protected MultipleBubblesService $multipleBubbles,
        protected StockInjectionService $stockInjection,
    ) {}

    /**
     * Run the agent loop with tool calling (Agentic Mode).
     *
     * @param  AgentLoopConfig  $config  All inputs for the agent run
     * @param  AgentLoopCallbacks  $callbacks  Event handler (SSE or sync)
     * @return AgentLoopResult Final response with usage data
     */
    public function run(AgentLoopConfig $config, AgentLoopCallbacks $callbacks): AgentLoopResult
    {
        $bot = $config->bot;
        $flow = $config->flow;
        $maxIterations = $flow->max_tool_calls ?? 10;
        $tools = $this->toolService->getToolDefinitions($flow->enabled_tools ?? []);
        $chatModel = $bot->decision_model ?: $this->getChatModel($bot);

        // Reset tool search cache for this request
        $this->toolService->resetCache();

        // === SAFETY: Initialize tracking ===
        $requestId = $this->costTracking->startRequest();
        $loopStartTime = microtime(true);
        $safetyConfig = $this->agentSafety->getSafetyConfig($flow);

        // Build initial messages with KB context and memory notes
        $systemPrompt = $this->buildAgentSystemPrompt($bot, $flow, $config->kbContext, $config->memoryNotes);
        $messages = $this->buildMessages($systemPrompt, $config->conversationHistory, $config->userMessage);

        $callbacks->onAgentStart([
            'max_iterations' => $maxIterations,
            'tools' => array_map(fn ($t) => $t['function']['name'] ?? 'unknown', $tools),
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
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $totalToolCalls = 0;
        $finalContent = '';
        $finalModel = $chatModel;

        while ($iteration < $maxIterations) {
            $iteration++;

            // === SAFETY: Check timeout + cost limits ===
            $safetyViolation = $this->agentSafety->checkLimits(
                $flow,
                $loopStartTime,
                $this->costTracking->getRunningCost(),
                $config->userId ?? 0
            );

            if ($safetyViolation) {
                $callbacks->onSafetyStop([
                    'type' => $safetyViolation['type'],
                    'details' => $safetyViolation,
                    'iteration' => $iteration,
                ]);

                $finalStatus = $safetyViolation['type'] === 'timeout' ? 'timeout' : 'cost_limit';
                break;
            }

            // === SAFETY: Check daily cost limit ===
            if ($config->userId) {
                $user = User::find($config->userId);
                if ($user && $this->costTracking->exceedsDailyLimit($user)) {
                    $callbacks->onSafetyStop([
                        'type' => 'daily_limit',
                        'message' => 'ถึงวงเงินรายวันแล้ว',
                        'iteration' => $iteration,
                    ]);

                    $finalStatus = 'cost_limit';
                    break;
                }
            }

            $callbacks->onThinking([
                'iteration' => $iteration,
                'elapsed_seconds' => round(microtime(true) - $loopStartTime, 1),
                'running_cost' => round($this->costTracking->getRunningCost(), 6),
            ]);

            try {
                // Truncate messages if approaching token limit
                $messages = $this->truncateMessagesIfNeeded($messages, 30, $flow->language ?? 'th');

                // Call LLM with tools
                $response = $this->openRouter->chatWithTools(
                    messages: $messages,
                    tools: $tools,
                    model: $chatModel,
                    temperature: $flow->temperature ?? 0.7,
                    maxTokens: $flow->max_tokens ?? 4096,
                    apiKeyOverride: $config->apiKey,
                    toolChoice: 'auto',
                    timeout: (int) config('services.openrouter.tool_timeout', 30)
                );

                // === SAFETY: Track cost ===
                $promptTokens = $response['usage']['prompt_tokens'] ?? 0;
                $completionTokens = $response['usage']['completion_tokens'] ?? 0;
                $this->costTracking->addCost($chatModel, $promptTokens, $completionTokens);

                $totalPromptTokens += $promptTokens;
                $totalCompletionTokens += $completionTokens;

                $finishReason = $response['finish_reason'] ?? 'stop';

                // Check if we should execute tools
                if ($finishReason === 'tool_calls' && ! empty($response['tool_calls'])) {
                    $processedToolCalls = [];
                    $toolResults = [];

                    foreach ($response['tool_calls'] as $toolCall) {
                        $totalToolCalls++;
                        $this->costTracking->addToolCall();

                        $toolId = $toolCall['id'] ?? 'tool_'.$iteration.'_'.count($processedToolCalls);
                        $toolName = $toolCall['function']['name'] ?? 'unknown';
                        $toolArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];

                        // === SAFETY: Check HITL approval for dangerous actions ===
                        if ($this->agentSafety->requiresApproval($flow, $toolName, $toolArgs)) {
                            if ($config->autoRejectHitl) {
                                // Webhook path: auto-reject dangerous actions
                                Log::info('AgentLoop: Auto-rejecting HITL action (webhook)', [
                                    'tool' => $toolName,
                                    'flow_id' => $flow->id,
                                ]);

                                $processedToolCalls[] = $toolCall;
                                $toolResults[] = [
                                    'tool_call_id' => $toolId,
                                    'content' => 'Action was auto-rejected: This action requires human approval which is not available in this context.',
                                ];

                                continue;
                            }

                            // SSE path: request approval and wait
                            $hitlTimeout = $flow->hitl_timeout ?? $flow->agent_timeout_seconds ?? 60;
                            $approvalId = $this->agentSafety->requestApproval(
                                $requestId,
                                $flow,
                                $toolName,
                                $toolArgs,
                                $hitlTimeout
                            );

                            $callbacks->onApprovalRequired([
                                'approval_id' => $approvalId,
                                'tool_name' => $toolName,
                                'tool_args' => $toolArgs,
                                'timeout_seconds' => $hitlTimeout,
                            ]);

                            // Wait for approval with heartbeat
                            $approval = $this->agentSafety->waitForApproval(
                                $approvalId,
                                $hitlTimeout,
                                fn ($elapsed, $timeout) => $callbacks->onApprovalWaiting([
                                    'approval_id' => $approvalId,
                                    'elapsed_seconds' => $elapsed,
                                    'timeout_seconds' => $timeout,
                                    'tool_name' => $toolName,
                                ])
                            );

                            $callbacks->onApprovalResponse([
                                'approval_id' => $approvalId,
                                'approved' => $approval['approved'],
                                'reason' => $approval['reason'] ?? null,
                            ]);

                            if (! $approval['approved']) {
                                $processedToolCalls[] = $toolCall;
                                $toolResults[] = [
                                    'tool_call_id' => $toolId,
                                    'content' => 'Action was rejected by user: '.($approval['reason'] ?? 'No reason provided'),
                                ];

                                continue;
                            }
                        }

                        // Send tool call event
                        $callbacks->onToolCall([
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
                        $callbacks->onToolResult([
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
                                : ('Error: '.($toolResult['error'] ?? 'Unknown error')),
                        ];
                    }

                    // Add ONE assistant message with ALL tool calls (OpenAI spec compliance)
                    if (! empty($processedToolCalls)) {
                        $messages[] = [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => $processedToolCalls,
                        ];

                        foreach ($toolResults as $result) {
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $result['tool_call_id'],
                                'content' => $result['content'],
                            ];
                        }
                    }

                    continue;
                }

                // No more tool calls - agent loop is done
                $finalContent = $response['content'] ?? '';
                $finalModel = $response['model'] ?? $chatModel;

                $callbacks->onAgentDone([
                    'iterations' => $iteration,
                    'total_tool_calls' => $totalToolCalls,
                    'total_cost' => round($this->costTracking->getRunningCost(), 6),
                    'elapsed_seconds' => round(microtime(true) - $loopStartTime, 1),
                ]);

                if (! empty($finalContent)) {
                    $callbacks->onContent($finalContent, $finalModel, 'agent_final_response');
                }

                break; // Exit loop

            } catch (\Exception $e) {
                Log::error('Agent loop error', [
                    'iteration' => $iteration,
                    'error' => $e->getMessage(),
                ]);

                $callbacks->onAgentError([
                    'iteration' => $iteration,
                    'error' => $e->getMessage(),
                ]);

                $finalStatus = 'error';
                $errorMessage = $e->getMessage();
                $language = $flow->language ?? 'th';
                $finalContent = config("agent-prompts.{$language}.error_message", config('agent-prompts.th.error_message'));
                break;
            }
        }

        // Max iterations reached - use RAGService pattern: call LLM with toolChoice='none'
        if ($iteration >= $maxIterations && $finalStatus === 'completed') {
            $callbacks->onMaxIterations([
                'iterations' => $iteration,
                'message' => 'ถึงจำนวนรอบสูงสุดแล้ว',
            ]);

            try {
                $fallbackResponse = $this->openRouter->chatWithTools(
                    messages: $messages,
                    tools: [],
                    model: $chatModel,
                    temperature: $flow->temperature ?? 0.7,
                    maxTokens: $flow->max_tokens ?? 4096,
                    apiKeyOverride: $config->apiKey,
                    toolChoice: 'none',
                    timeout: (int) config('services.openrouter.tool_timeout', 30)
                );

                $promptTokens = $fallbackResponse['usage']['prompt_tokens'] ?? 0;
                $completionTokens = $fallbackResponse['usage']['completion_tokens'] ?? 0;
                $this->costTracking->addCost($chatModel, $promptTokens, $completionTokens);
                $totalPromptTokens += $promptTokens;
                $totalCompletionTokens += $completionTokens;

                $finalContent = $fallbackResponse['content'] ?? '';
                $finalModel = $fallbackResponse['model'] ?? $chatModel;
                $finalStatus = 'max_iterations';

                if (! empty($finalContent)) {
                    $callbacks->onContent($finalContent, $finalModel, 'max_iterations_fallback');
                }
            } catch (\Exception $e) {
                Log::error('Agent loop max-iterations fallback failed', [
                    'error' => $e->getMessage(),
                ]);
                $finalStatus = 'error';
                $errorMessage = $e->getMessage();
                $language = $flow->language ?? 'th';
                $finalContent = config("agent-prompts.{$language}.error_message", config('agent-prompts.th.error_message'));
            }
        }

        // === SAFETY: Finalize cost tracking ===
        $durationMs = (int) round((microtime(true) - $loopStartTime) * 1000);

        if ($config->userId) {
            $this->costTracking->finalizeRequest(
                userId: $config->userId,
                botId: $bot->id,
                flowId: $flow->id,
                status: $finalStatus,
                durationMs: $durationMs,
                iterations: $iteration,
                modelUsed: $chatModel,
                errorMessage: $errorMessage
            );
        }

        return new AgentLoopResult(
            content: $finalContent,
            model: $finalModel,
            usage: [
                'prompt_tokens' => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
                'total_tokens' => $totalPromptTokens + $totalCompletionTokens,
            ],
            cost: $this->costTracking->getRunningCost(),
            agentic: [
                'iterations' => $iteration,
                'tool_calls' => $totalToolCalls,
                'status' => $finalStatus,
                'error' => $errorMessage,
            ],
        );
    }

    /**
     * Smart routing: determine whether to use the agent loop or standard chat.
     *
     * Receives pre-computed signals to avoid circular dependency with RAGService.
     *
     * @param  array{is_complex: bool, score: int}  $complexity  From RAGService::detectComplexity()
     * @param  array{needs_tool: bool, tool_hint: ?string}  $toolIntent  From RAGService::detectToolIntent()
     * @param  float  $kbTopRelevance  Top KB result relevance score
     * @param  float  $threshold  KB similarity threshold from Flow settings
     * @return array{use_agent: bool, reason: string, complexity_score: int, kb_top_relevance: float, tool_intent: bool}
     */
    public function shouldUseAgentLoop(
        array $complexity,
        array $toolIntent,
        float $kbTopRelevance,
        float $threshold
    ): array {
        // Greeting circuit breaker — ALWAYS bypass agent loop
        if (! $complexity['is_complex'] && in_array('greeting_detected', $complexity['reasons'] ?? [])) {
            return [
                'use_agent' => false,
                'reason' => 'greeting_detected',
                'complexity_score' => 0,
                'kb_top_relevance' => $kbTopRelevance,
                'tool_intent' => false,
            ];
        }

        $hasHighQualityKb = $kbTopRelevance >= $threshold;

        $useAgent = $complexity['is_complex']
            || $toolIntent['needs_tool']
            || ! $hasHighQualityKb;

        $reason = match (true) {
            ! $useAgent => 'simple_with_quality_kb',
            $toolIntent['needs_tool'] => 'tool_intent:'.($toolIntent['tool_hint'] ?? 'unknown'),
            $complexity['is_complex'] => 'complex_question',
            ! $hasHighQualityKb && $kbTopRelevance > 0 => 'low_quality_kb',
            default => 'no_kb_results',
        };

        return [
            'use_agent' => $useAgent,
            'reason' => $reason,
            'complexity_score' => $complexity['score'] ?? 0,
            'kb_top_relevance' => $kbTopRelevance,
            'tool_intent' => $toolIntent['needs_tool'] ?? false,
        ];
    }

    /**
     * Build system prompt for agent mode with KB context and Multiple Bubbles support.
     */
    public function buildAgentSystemPrompt(Bot $bot, Flow $flow, string $kbContext = '', array $memoryNotes = []): string
    {
        $stocks = $this->stockInjection->getStockStatus();
        $hasOutOfStock = $stocks->where('in_stock', false)->isNotEmpty();

        $stockBlock = '';
        if ($hasOutOfStock) {
            $stockInjection = $this->stockInjection->buildStockInjection($stocks);
            if (! empty($stockInjection)) {
                $stockBlock = $stockInjection."\n---\n\n";
            }
        }

        $basePrompt = $stockBlock
            .$this->buildMemoryPrefix($memoryNotes)
            .($flow->system_prompt ?: $this->getDefaultSystemPrompt($bot));
        $enabledTools = $flow->enabled_tools ?? [];

        if (empty($enabledTools)) {
            return $basePrompt;
        }

        $language = $flow->language ?? 'th';
        $prompts = config("agent-prompts.{$language}", config('agent-prompts.th'));

        $prompt = $basePrompt;

        // --- Pre-loaded KB Context ---
        if (! empty(trim($kbContext))) {
            $prompt .= "\n\n".$prompts['pre_loaded_kb'];
            $prompt .= $kbContext;
            $prompt .= $prompts['pre_loaded_kb_suffix'];
        }

        // --- Agent Decision Framework ---
        $prompt .= "\n\n".$prompts['agent_decision_framework'];
        $prompt .= $prompts['decision_instant'];

        if (in_array('search_kb', $enabledTools)) {
            $prompt .= ! empty($kbContext)
                ? $prompts['decision_search_with_kb']
                : $prompts['decision_search_no_kb'];
        }
        if (in_array('calculate', $enabledTools)) {
            $prompt .= $prompts['decision_calculate'];
        }
        if (in_array('think', $enabledTools)) {
            $prompt .= $prompts['decision_think'];
        }
        if (in_array('get_current_datetime', $enabledTools)) {
            $prompt .= $prompts['decision_datetime'];
        }
        if (in_array('escalate_to_human', $enabledTools)) {
            $prompt .= $prompts['decision_escalate'];
        }

        // --- Search Strategy ---
        if (in_array('search_kb', $enabledTools)) {
            $prompt .= "\n".$prompts['search_strategy'];
        }

        // --- Response Rules ---
        $prompt .= "\n".$prompts['response_rules'];

        // --- Multiple Bubbles Integration ---
        if ($this->multipleBubbles->isEnabled($bot)) {
            $prompt .= "\n".$this->multipleBubbles->buildPromptInstruction($bot);
        }

        if ($hasOutOfStock) {
            $stockReminder = $this->stockInjection->buildStockReminder($stocks);
            if (! empty($stockReminder)) {
                $prompt .= "\n\n".$stockReminder;
            }
        }

        return $prompt;
    }

    /**
     * Build memory notes prefix for system prompt.
     */
    public function buildMemoryPrefix(array $memoryNotes): string
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

    /**
     * Truncate messages array if approaching token limit.
     * Keeps system message + recent messages to prevent memory growth.
     */
    public function truncateMessagesIfNeeded(array $messages, int $maxMessages = 30, string $language = 'th'): array
    {
        $count = count($messages);

        // Tier 1 (> 20 messages): Compress old tool results
        if ($count > 20) {
            $messages = $this->compressOldToolResults($messages);
        }

        // Tier 2 (> maxMessages): Drop oldest messages
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
        while (! empty($truncatedMessages) && $truncatedMessages[0]['role'] === 'tool') {
            array_shift($truncatedMessages);
        }

        // Rebuild with system message first
        $result = [];
        if ($systemMessage) {
            $result[] = $systemMessage;

            // Inject context note about dropped messages
            $dropped = count($otherMessages) - count($truncatedMessages);
            if ($dropped > 0) {
                $truncationNote = config("agent-prompts.{$language}.truncation_note", config('agent-prompts.th.truncation_note'));
                $result[] = [
                    'role' => 'user',
                    'content' => sprintf($truncationNote, $dropped),
                ];
            }
        }
        $result = array_merge($result, $truncatedMessages);

        return $result;
    }

    /**
     * Compress old tool result messages to save tokens.
     * Keeps last 2 tool results intact, compresses older ones.
     */
    public function compressOldToolResults(array $messages): array
    {
        $toolIndices = [];
        foreach ($messages as $i => $msg) {
            if ($msg['role'] === 'tool') {
                $toolIndices[] = $i;
            }
        }

        $indicesToCompress = array_slice($toolIndices, 0, max(0, count($toolIndices) - 2));

        if (empty($indicesToCompress)) {
            return $messages;
        }

        foreach ($indicesToCompress as $i) {
            $content = $messages[$i]['content'] ?? '';
            if (mb_strlen($content) > 100) {
                $messages[$i]['content'] = mb_substr($content, 0, 100).' [compressed]';
            }
        }

        return $messages;
    }

    /**
     * Build messages array for the LLM.
     */
    protected function buildMessages(string $systemPrompt, array $conversationHistory, string $message): array
    {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($conversationHistory as $msg) {
            // Support both 'role' (chat emulator) and 'sender' (webhook path)
            $role = $msg['role'] ?? ($msg['sender'] === 'user' ? 'user' : 'assistant');
            $messages[] = [
                'role' => $role,
                'content' => $msg['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        return $messages;
    }

    /**
     * Get default system prompt for a bot.
     */
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
     * Get the chat model for a bot.
     */
    protected function getChatModel(Bot $bot): string
    {
        return $bot->primary_chat_model
            ?: $bot->fallback_chat_model
            ?: config('services.openrouter.default_model', 'anthropic/claude-3.5-sonnet');
    }

    /**
     * Truncate text for preview in events.
     */
    protected function truncateForPreview(string $text, int $maxLength = 200): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength).'...';
    }
}
