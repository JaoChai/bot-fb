<?php

namespace App\Services\SecondAI;

use App\Models\Flow;
use App\Services\OpenRouterService;
use App\Services\RAGService;
use Illuminate\Support\Facades\Log;

class UnifiedCheckService
{
    /**
     * Timeout for Second AI API calls (seconds).
     * Lower than default to prevent Chat Emulator from hanging.
     */
    protected int $timeout = 15;

    public function __construct(
        protected OpenRouterService $openRouter,
        protected RAGService $ragService,
    ) {}

    /**
     * Execute unified check for all enabled options
     *
     * @param string $response Original AI response to check
     * @param Flow $flow Flow with second_ai_options configuration
     * @param string $userMessage Original user message for context
     * @param string|null $apiKey Optional API key override
     * @return SecondAICheckResult Structured check result
     * @throws \RuntimeException If LLM call fails or returns invalid JSON
     */
    public function check(
        string $response,
        Flow $flow,
        string $userMessage,
        ?string $apiKey = null
    ): SecondAICheckResult {
        $startTime = microtime(true);
        $enabledChecks = $this->getEnabledChecks($flow);

        Log::info('UnifiedCheckService: Starting unified check', [
            'enabled_checks' => $enabledChecks,
            'response_length' => strlen($response),
        ]);

        // Fetch Knowledge Base context if fact_check enabled
        $kbContext = '';
        if (in_array('fact_check', $enabledChecks) && $flow->knowledgeBases()->count() > 0) {
            try {
                $metadata = [];
                $kbContext = $this->ragService->getFlowKnowledgeBaseContext($flow, $userMessage, $metadata);
            } catch (\Exception $e) {
                Log::warning('UnifiedCheckService: Failed to fetch KB context', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Build unified prompt
        $prompt = $this->buildUnifiedPrompt($response, $flow, $userMessage, $kbContext);

        // Get models from Bot Settings (same as Decision/Intent Analysis)
        $bot = $flow->bot;
        $model = $bot?->decision_model
            ?: $bot?->primary_chat_model
            ?: 'openai/gpt-4o-mini';
        $fallbackModel = $bot?->fallback_decision_model
            ?: $bot?->fallback_chat_model
            ?: 'google/gemini-flash-1.5';

        Log::info('UnifiedCheckService: Using models from Bot Settings', [
            'primary_model' => $model,
            'fallback_model' => $fallbackModel,
        ]);

        try {
            $response = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $model,
                temperature: 0.3,
                maxTokens: 2000,
                useFallback: true, // Enable fallback for reliability
                apiKeyOverride: $apiKey,
                fallbackModelOverride: $fallbackModel,
                timeout: $this->timeout,
            );

            $rawResponse = $response['content'];
        } catch (\Exception $e) {
            Log::error('UnifiedCheckService: LLM call failed', [
                'error' => $e->getMessage(),
                'timeout' => $this->timeout,
            ]);
            throw new \RuntimeException('Unified check LLM call failed: '.$e->getMessage());
        }

        // Parse and validate response
        $parsedData = $this->parseResponse($rawResponse);

        $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('UnifiedCheckService: Check completed', [
            'elapsed_ms' => $elapsedMs,
            'passed' => $parsedData['passed'],
            'checks_applied' => array_keys(array_filter(
                $parsedData['modifications'] ?? [],
                fn($mod) => $mod['required'] ?? false
            )),
        ]);

        // Create result object
        return SecondAICheckResult::fromJson([
            'passed' => $parsedData['passed'],
            'modifications' => $parsedData['modifications'],
            'final_response' => $parsedData['final_response'],
            'model_used' => $response['model'] ?? $model,
            'latency_ms' => $elapsedMs,
        ]);
    }

    /**
     * Build unified prompt combining all enabled checks
     */
    protected function buildUnifiedPrompt(
        string $response,
        Flow $flow,
        string $userMessage,
        string $kbContext = ''
    ): string {
        $enabledChecks = $this->getEnabledChecks($flow);
        $systemPrompt = $flow->system_prompt ?? 'You are a helpful assistant.';

        $prompt = "You are a quality control AI that reviews chatbot responses for accuracy, policy compliance, and brand personality.\n\n";
        $prompt .= "# Context\n\n";
        $prompt .= "**User Message**: {$userMessage}\n\n";
        $prompt .= "**Original AI Response**: {$response}\n\n";
        $prompt .= "**Bot Personality**: {$systemPrompt}\n\n";

        // Add enabled checks sections
        $checksDescription = [];

        if (in_array('fact_check', $enabledChecks)) {
            $prompt .= "# Knowledge Base Context\n\n";
            if (!empty($kbContext)) {
                $prompt .= $kbContext . "\n\n";
            } else {
                $prompt .= "No Knowledge Base available.\n\n";
            }

            $checksDescription[] = "**Fact Check**: Identify all factual claims. Mark claims as unverified if they cannot be confirmed by the Knowledge Base context above. Rewrite the response removing or rephrasing unverified claims.";
        }

        if (in_array('policy', $enabledChecks)) {
            $policyRules = $flow->second_ai_options['policy_rules'] ?? 'Follow general business ethics and consumer protection laws.';
            $prompt .= "# Policy Rules\n\n{$policyRules}\n\n";

            $checksDescription[] = "**Policy Check**: Check for violations of the policy rules above. Rewrite the response to comply with all policies if violations found.";
        }

        if (in_array('personality', $enabledChecks)) {
            $checksDescription[] = "**Personality Check**: Ensure the response matches the bot personality described above. Check tone, formality, language style. Rewrite if the tone doesn't match the brand voice.";
        }

        $prompt .= "# Your Task\n\n";
        $prompt .= "Perform these checks:\n\n";
        foreach ($checksDescription as $idx => $desc) {
            $prompt .= ($idx + 1).". {$desc}\n";
        }

        $prompt .= "\n# Output Format\n\n";
        $prompt .= "Respond with ONLY a valid JSON object (no markdown, no explanation). Structure:\n\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= '  "passed": boolean,  // false if ANY check requires modification'."\n";
        $prompt .= '  "modifications": {'."\n";

        if (in_array('fact_check', $enabledChecks)) {
            $prompt .= '    "fact_check": {'."\n";
            $prompt .= '      "required": boolean,'."\n";
            $prompt .= '      "claims_extracted": ["claim1", "claim2"],'."\n";
            $prompt .= '      "unverified_claims": ["claim1"],'."\n";
            $prompt .= '      "rewritten": "rewritten text" | null'."\n";
            $prompt .= '    },'."\n";
        }

        if (in_array('policy', $enabledChecks)) {
            $prompt .= '    "policy": {'."\n";
            $prompt .= '      "required": boolean,'."\n";
            $prompt .= '      "violations": ["violation1"],'."\n";
            $prompt .= '      "rewritten": "rewritten text" | null'."\n";
            $prompt .= '    },'."\n";
        }

        if (in_array('personality', $enabledChecks)) {
            $prompt .= '    "personality": {'."\n";
            $prompt .= '      "required": boolean,'."\n";
            $prompt .= '      "issues": ["issue1"],'."\n";
            $prompt .= '      "rewritten": "rewritten text" | null'."\n";
            $prompt .= '    }'."\n";
        }

        $prompt .= '  },'."\n";
        $prompt .= '  "final_response": "final improved response after applying all modifications sequentially"'."\n";
        $prompt .= "}\n";
        $prompt .= "```\n\n";

        $prompt .= "# Important Rules\n\n";
        $prompt .= "- If a check passes (no issues), set `required: false` and `rewritten: null`\n";
        $prompt .= "- If a check fails, set `required: true` and provide `rewritten` text\n";
        $prompt .= "- Apply modifications sequentially: fact_check → policy → personality\n";
        $prompt .= "- `final_response` must be the result after applying ALL modifications\n";
        $prompt .= "- Return ONLY the JSON object, no other text\n";

        return $prompt;
    }

    /**
     * Parse and validate LLM JSON response
     */
    protected function parseResponse(string $rawResponse): array
    {
        // Strip markdown code blocks if present
        $cleaned = preg_replace('/```json\s*|\s*```/', '', trim($rawResponse));

        try {
            $json = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::error('UnifiedCheckService: Invalid JSON response', [
                'error' => $e->getMessage(),
                'raw_response' => substr($rawResponse, 0, 500),
            ]);
            throw new \RuntimeException('Invalid JSON response from unified check');
        }

        // Validate required fields
        if (!isset($json['passed']) || !is_bool($json['passed'])) {
            throw new \RuntimeException('Missing or invalid "passed" field in unified check response');
        }

        if (!isset($json['modifications']) || !is_array($json['modifications'])) {
            throw new \RuntimeException('Missing or invalid "modifications" field in unified check response');
        }

        if (!isset($json['final_response']) || !is_string($json['final_response']) || empty($json['final_response'])) {
            throw new \RuntimeException('Missing or invalid "final_response" field in unified check response');
        }

        return $json;
    }

    /**
     * Get list of enabled check types from flow configuration
     */
    protected function getEnabledChecks(Flow $flow): array
    {
        $options = $flow->second_ai_options ?? [];
        $enabled = [];

        if ($options['fact_check'] ?? false) {
            $enabled[] = 'fact_check';
        }
        if ($options['policy'] ?? false) {
            $enabled[] = 'policy';
        }
        if ($options['personality'] ?? false) {
            $enabled[] = 'personality';
        }

        return $enabled;
    }
}
