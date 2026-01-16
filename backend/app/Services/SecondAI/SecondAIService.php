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
 */
class SecondAIService
{
    /**
     * Maximum time (seconds) for all Second AI checks combined.
     */
    protected int $timeout = 5;

    public function __construct(
        protected FactCheckService $factCheck,
        protected PolicyCheckService $policyCheck,
        protected PersonalityCheckService $personalityCheck,
        protected UnifiedCheckService $unifiedCheck,
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
        ?string $apiKey = null
    ): array {
        // Quick exit if Second AI is not enabled
        if (!$flow->second_ai_enabled) {
            return $this->buildResult($response, false, []);
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
                $result = $this->unifiedCheck->check($response, $flow, $userMessage, $apiKey);

                Log::info('SecondAI: Unified mode completed', [
                    'flow_id' => $flow->id,
                    'passed' => $result->passed,
                    'elapsed_ms' => $result->metadata['latency_ms'] ?? 0,
                ]);

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
                    $factResult = $this->factCheck->check(
                        $currentContent,
                        $flow,
                        $userMessage,
                        $apiKey
                    );

                    $checksApplied[] = 'fact_check';
                    if ($factResult->wasModified) {
                        $currentContent = $factResult->content;
                        $modifications['fact_check'] = $factResult->modifications;
                    }
                }

                // 2. Policy Check (if enabled)
                if (!empty($options['policy'])) {
                    $this->checkTimeout($startTime);
                    $policyResult = $this->policyCheck->check(
                        $currentContent,
                        $flow,
                        $apiKey
                    );

                    $checksApplied[] = 'policy';
                    if ($policyResult->wasModified) {
                        $currentContent = $policyResult->content;
                        $modifications['policy'] = $policyResult->modifications;
                    }
                }

                // 3. Personality Check (if enabled)
                if (!empty($options['personality'])) {
                    $this->checkTimeout($startTime);
                    $personalityResult = $this->personalityCheck->check(
                        $currentContent,
                        $flow,
                        $apiKey
                    );

                    $checksApplied[] = 'personality';
                    if ($personalityResult->wasModified) {
                        $currentContent = $personalityResult->content;
                        $modifications['personality'] = $personalityResult->modifications;
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

            return $this->buildResult($currentContent, true, [
                'checks_applied' => $checksApplied,
                'modifications' => $modifications,
                'elapsed_ms' => $elapsed,
            ]);
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
}
