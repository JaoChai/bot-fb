<?php

namespace App\Services\SecondAI;

use App\Models\Flow;
use Illuminate\Support\Facades\Log;

/**
 * SecondAIService - Orchestrates Second AI checks on responses
 *
 * Coordinates the execution of Fact Check, Policy Check, and Personality Check
 * services in sequence. Implements a timeout and graceful fallback strategy
 * to ensure user experience is not impacted if checks fail or timeout.
 *
 * Now includes prompt injection detection as an input guardrail.
 */
class SecondAIService
{
    /**
     * Maximum time (seconds) for all Second AI checks combined.
     */
    protected int $timeout = 5;

    /**
     * Maximum time (seconds) per individual check.
     */
    protected int $perCheckTimeout = 2;

    public function __construct(
        protected FactCheckService $factCheck,
        protected PolicyCheckService $policyCheck,
        protected PersonalityCheckService $personalityCheck,
        protected UnifiedCheckService $unifiedCheck,
        protected PromptInjectionDetector $injectionDetector,
        protected SecondAIMetricsService $metricsService,
    ) {}

    /**
     * Process a response through enabled Second AI checks.
     *
     * Executes checks in sequence: Fact → Policy → Personality
     * Each check receives the output of the previous check as input.
     *
     * @param string $response The original AI response
     * @param Flow $flow The flow with second_ai configuration
     * @param string $userMessage The original user message (for context)
     * @param string|null $apiKey Optional API key override
     * @return array Response with second_ai metadata
     */
    public function process(
        string $response,
        Flow $flow,
        string $userMessage,
        ?string $apiKey = null,
        string $kbContext = ''
    ): array {
        // Quick exit if Second AI is not enabled
        if (!$flow->second_ai_enabled) {
            return $this->buildResult($response, false, []);
        }

        $skipReason = $this->shouldSkipCheck($response);
        if ($skipReason !== null) {
            Log::info('SecondAI: Skipping', [
                'flow_id' => $flow->id,
                'reason' => $skipReason,
            ]);
            return $this->buildResult($response, false, [
                'skipped' => true,
                'skip_reason' => $skipReason,
            ]);
        }

        $options = $flow->second_ai_options ?? [];
        $startTime = microtime(true);

        // Try unified mode first if applicable
        if ($this->shouldUseUnifiedMode($options)) {
            Log::info('SecondAI: Using unified mode', [
                'flow_id' => $flow->id,
                'enabled_checks' => array_keys(array_filter($options)),
            ]);

            try {
                $result = $this->unifiedCheck->check($response, $flow, $userMessage, $apiKey, $kbContext);

                Log::info('SecondAI: Unified mode completed', [
                    'flow_id' => $flow->id,
                    'passed' => $result->passed,
                    'elapsed_ms' => $result->metadata['latency_ms'] ?? 0,
                ]);

                // Log metrics for analytics
                $this->metricsService->logMetrics(
                    botId: $flow->bot_id,
                    flowId: $flow->id,
                    result: $result,
                    executionMode: 'unified'
                );

                // Convert to legacy format for backward compatibility
                return $result->toLegacyFormat();
            } catch (\Exception $e) {
                Log::warning('SecondAI: Unified mode failed, falling back to sequential', [
                    'flow_id' => $flow->id,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to sequential mode below
            }
        }

        // Sequential mode (original implementation or fallback)
        $currentContent = $response;
        $modifications = [];
        $checksApplied = [];

        Log::info('SecondAI: Using sequential mode', [
            'flow_id' => $flow->id,
            'options' => $options,
        ]);

        try {
            // Use Laravel rescue for timeout-safe execution
            $result = rescue(function () use (
                &$currentContent,
                &$modifications,
                &$checksApplied,
                $options,
                $flow,
                $userMessage,
                $apiKey,
                $startTime
            ) {
                // 1. Fact Check (if enabled)
                if (!empty($options['fact_check'])) {
                    $this->checkTimeout($startTime);
                    $checkResult = rescue(function () use ($currentContent, $flow, $userMessage, $apiKey) {
                        $checkStart = microtime(true);
                        $result = $this->factCheck->check($currentContent, $flow, $userMessage, $apiKey);
                        if (microtime(true) - $checkStart > $this->perCheckTimeout) {
                            Log::warning('SecondAI: fact_check exceeded per-check timeout', [
                                'elapsed' => round(microtime(true) - $checkStart, 2),
                            ]);
                        }
                        return $result;
                    }, function (\Throwable $e) {
                        Log::warning('SecondAI: fact_check failed', ['error' => $e->getMessage()]);
                        return null;
                    }, report: false);

                    $checksApplied[] = 'fact_check';
                    if ($checkResult?->wasModified) {
                        $currentContent = $checkResult->content;
                        $modifications['fact_check'] = $checkResult->modifications;
                    }
                }

                // 2. Policy Check (if enabled)
                if (!empty($options['policy'])) {
                    $this->checkTimeout($startTime);
                    $checkResult = rescue(function () use ($currentContent, $flow, $apiKey) {
                        $checkStart = microtime(true);
                        $result = $this->policyCheck->check($currentContent, $flow, $apiKey);
                        if (microtime(true) - $checkStart > $this->perCheckTimeout) {
                            Log::warning('SecondAI: policy exceeded per-check timeout', [
                                'elapsed' => round(microtime(true) - $checkStart, 2),
                            ]);
                        }
                        return $result;
                    }, function (\Throwable $e) {
                        Log::warning('SecondAI: policy failed', ['error' => $e->getMessage()]);
                        return null;
                    }, report: false);

                    $checksApplied[] = 'policy';
                    if ($checkResult?->wasModified) {
                        $currentContent = $checkResult->content;
                        $modifications['policy'] = $checkResult->modifications;
                    }
                }

                // 3. Personality Check (if enabled)
                if (!empty($options['personality'])) {
                    $this->checkTimeout($startTime);
                    $checkResult = rescue(function () use ($currentContent, $flow, $apiKey) {
                        $checkStart = microtime(true);
                        $result = $this->personalityCheck->check($currentContent, $flow, $apiKey);
                        if (microtime(true) - $checkStart > $this->perCheckTimeout) {
                            Log::warning('SecondAI: personality exceeded per-check timeout', [
                                'elapsed' => round(microtime(true) - $checkStart, 2),
                            ]);
                        }
                        return $result;
                    }, function (\Throwable $e) {
                        Log::warning('SecondAI: personality failed', ['error' => $e->getMessage()]);
                        return null;
                    }, report: false);

                    $checksApplied[] = 'personality';
                    if ($checkResult?->wasModified) {
                        $currentContent = $checkResult->content;
                        $modifications['personality'] = $checkResult->modifications;
                    }
                }

                return true;
            }, function (\Throwable $e) use ($response, $flow) {
                // Fallback on timeout or error
                Log::error('SecondAI: Checks failed', [
                    'flow_id' => $flow->id,
                    'error' => $e->getMessage(),
                ]);
                error_log('SecondAI Sequential ERROR: ' . $e->getMessage());
                return false;
            }, false);

            $elapsed = round((microtime(true) - $startTime) * 1000);

            if ($result === false) {
                // Fallback was triggered
                return $this->buildResult($response, false, [
                    'error' => 'timeout_or_error',
                    'elapsed_ms' => $elapsed,
                ]);
            }

            Log::info('SecondAI: Checks completed', [
                'flow_id' => $flow->id,
                'checks_applied' => $checksApplied,
                'was_modified' => !empty($modifications),
                'elapsed_ms' => $elapsed,
            ]);

            $resultArray = $this->buildResult($currentContent, !empty($modifications), [
                'checks_applied' => $checksApplied,
                'modifications' => $modifications,
                'elapsed_ms' => $elapsed,
                'model_used' => $flow->bot?->decision_model ?: $flow->bot?->primary_chat_model,
            ]);

            // Log metrics for analytics (sequential mode)
            $this->metricsService->logMetricsFromLegacy(
                botId: $flow->bot_id,
                flowId: $flow->id,
                result: $resultArray,
                executionMode: 'sequential'
            );

            return $resultArray;
        } catch (\Exception $e) {
            Log::error('SecondAI: Unexpected error', [
                'error' => $e->getMessage(),
                'flow_id' => $flow->id,
            ]);

            // Graceful fallback - return original response
            return $this->buildResult($response, false, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if we've exceeded the timeout.
     *
     * @param float $startTime Start time from microtime(true)
     * @throws \RuntimeException if timeout exceeded
     */
    protected function checkTimeout(float $startTime): void
    {
        $elapsed = microtime(true) - $startTime;
        if ($elapsed >= $this->timeout) {
            throw new \RuntimeException("Second AI timeout exceeded: {$elapsed}s");
        }
    }

    /**
     * Build the result array.
     *
     * @param string $content The final response content
     * @param bool $applied Whether Second AI checks were applied
     * @param array $metadata Additional metadata
     * @return array
     */
    protected function buildResult(string $content, bool $applied, array $metadata = []): array
    {
        return [
            'content' => $content,
            'second_ai_applied' => $applied,
            'second_ai' => $metadata,
        ];
    }

    /**
     * Set the timeout for Second AI checks.
     *
     * @param int $seconds Timeout in seconds
     * @return self
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Check user input for prompt injection attacks.
     *
     * Call this BEFORE generating AI response to block malicious inputs early.
     *
     * @param string $userMessage User input to check
     * @param Flow $flow Flow for configuration and logging
     * @param int|null $conversationId Optional conversation ID for logging
     * @return DetectionResult Detection result with action and risk score
     */
    public function checkUserInput(
        string $userMessage,
        Flow $flow,
        ?int $conversationId = null
    ): DetectionResult {
        $result = $this->injectionDetector->detect($userMessage);

        // Log if detected (blocked or flagged)
        if ($result->detected) {
            $this->injectionDetector->log(
                $flow->bot_id,
                $userMessage,
                $result,
                $conversationId
            );

            Log::warning('SecondAI: Injection attempt detected', [
                'flow_id' => $flow->id,
                'bot_id' => $flow->bot_id,
                'action' => $result->action,
                'risk_score' => $result->riskScore,
                'patterns' => $result->getPatternNames(),
            ]);
        }

        return $result;
    }

    /**
     * Check if user input should be blocked due to injection attempt.
     *
     * @param string $userMessage User input to check
     * @param Flow $flow Flow for configuration
     * @param int|null $conversationId Optional conversation ID
     * @return bool True if input should be blocked
     */
    public function shouldBlockInput(
        string $userMessage,
        Flow $flow,
        ?int $conversationId = null
    ): bool {
        return $this->checkUserInput($userMessage, $flow, $conversationId)->isBlocked();
    }

    /**
     * Get the injection detector instance.
     *
     * @return PromptInjectionDetector
     */
    public function getInjectionDetector(): PromptInjectionDetector
    {
        return $this->injectionDetector;
    }

    /**
     * Check if unified mode should be used.
     *
     * Unified mode is enabled when 2 or more check options are enabled.
     * This provides better performance by combining checks into a single LLM call.
     *
     * @param array $options Second AI options from flow configuration
     * @return bool
     */
    protected function shouldUseUnifiedMode(array $options): bool
    {
        $enabledCount = 0;

        if (!empty($options['fact_check'])) {
            $enabledCount++;
        }
        if (!empty($options['policy'])) {
            $enabledCount++;
        }
        if (!empty($options['personality'])) {
            $enabledCount++;
        }

        return $enabledCount >= 2;
    }

    /**
     * Check if the response should skip Second AI checks.
     *
     * @param string $response The AI response to evaluate
     * @return string|null Skip reason, or null if check should proceed
     */
    protected function shouldSkipCheck(string $response): ?string
    {
        $trimmed = trim($response);

        // Skip very short messages without factual content
        // But don't skip if it contains numbers (prices, quantities, dates)
        if (mb_strlen($trimmed) < 50 && !preg_match('/\d/', $trimmed)) {
            return 'response_too_short';
        }

        // Skip greeting-only patterns (must match entire response, not just prefix)
        $patterns = [
            '/^(สวัสดี|หวัดดี|ดีค่ะ|ดีครับ|ขอบคุณ|ยินดี|รับทราบ)[ค่ะครับคะนะจ้า\s!\.]*$/u',
            '/^(ยินดีให้บริการ|มีอะไรให้ช่วย|สอบถามเพิ่มเติม)[ค่ะครับคะนะจ้า\s!\.]*$/u',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $trimmed)) {
                return 'greeting_or_acknowledgment';
            }
        }

        return null;
    }
}
