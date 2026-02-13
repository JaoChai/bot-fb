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
            $apiResult = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $model,
                temperature: 0.3,
                maxTokens: 2000,
                useFallback: true, // Enable fallback for reliability
                apiKeyOverride: $apiKey,
                fallbackModelOverride: $fallbackModel,
                timeout: $this->timeout,
            );

            $rawResponse = $apiResult['content'];
            error_log('UNIFIED_RAW_RESPONSE: ' . substr($rawResponse, 0, 300));
        } catch (\Exception $e) {
            Log::error('UnifiedCheckService: LLM call failed', [
                'error' => $e->getMessage(),
                'timeout' => $this->timeout,
            ]);
            error_log('UNIFIED_LLM_FAIL: ' . $e->getMessage());
            throw new \RuntimeException('Unified check LLM call failed: '.$e->getMessage());
        }

        // Parse and validate response
        try {
            $parsedData = $this->parseResponse($rawResponse);
            error_log('UNIFIED_PARSE_OK: passed=' . ($parsedData['passed'] ? 'true' : 'false'));
        } catch (\Exception $e) {
            error_log('UNIFIED_PARSE_FAIL: ' . $e->getMessage());
            throw $e;
        }

        // Filter out low-confidence modifications
        $parsedData['modifications'] = $this->filterByConfidence($parsedData['modifications']);
        $parsedData['passed'] = $this->inferPassedFromModifications($parsedData['modifications']);
        if ($parsedData['passed']) {
            $parsedData['final_response'] = $response;
        }

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
            'model_used' => $apiResult['model'] ?? $model,
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
            $prompt .= '      "confidence": 0.0-1.0,'."\n";
            $prompt .= '      "claims_extracted": ["claim1", "claim2"],'."\n";
            $prompt .= '      "unverified_claims": ["claim1"],'."\n";
            $prompt .= '      "rewritten": "rewritten text" | null'."\n";
            $prompt .= '    },'."\n";
        }

        if (in_array('policy', $enabledChecks)) {
            $prompt .= '    "policy": {'."\n";
            $prompt .= '      "required": boolean,'."\n";
            $prompt .= '      "confidence": 0.0-1.0,'."\n";
            $prompt .= '      "violations": ["violation1"],'."\n";
            $prompt .= '      "rewritten": "rewritten text" | null'."\n";
            $prompt .= '    },'."\n";
        }

        if (in_array('personality', $enabledChecks)) {
            $prompt .= '    "personality": {'."\n";
            $prompt .= '      "required": boolean,'."\n";
            $prompt .= '      "confidence": 0.0-1.0,'."\n";
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
        $prompt .= "- Include confidence (0.0-1.0) for each check reflecting how certain you are about the issue\n";
        $prompt .= "- Only set required: true if confidence >= 0.7\n";
        $prompt .= "- Return ONLY the JSON object, no other text\n\n";

        // Add examples section
        $prompt .= $this->buildExamplesSection($enabledChecks);

        return $prompt;
    }

    /**
     * Build examples section for few-shot learning
     *
     * @param array $enabledChecks List of enabled check types
     * @return string Examples prompt section
     */
    protected function buildExamplesSection(array $enabledChecks): string
    {
        $examples = "# Examples\n\n";

        // Good example - all checks pass
        $examples .= "## Example 1: All Checks Pass\n";
        $examples .= "User: ราคาสินค้า A เท่าไหร่\n";
        $examples .= "Response: สินค้า A ราคา 599 บาทค่ะ (ข้อมูลจาก KB ตรงกัน)\n";
        $examples .= "Result:\n```json\n";
        $examples .= "{\n";
        $examples .= '  "passed": true,'."\n";
        $examples .= '  "modifications": {'."\n";

        if (in_array('fact_check', $enabledChecks)) {
            $examples .= '    "fact_check": { "required": false, "claims_extracted": ["ราคา 599 บาท"], "unverified_claims": [], "rewritten": null },'."\n";
        }
        if (in_array('policy', $enabledChecks)) {
            $examples .= '    "policy": { "required": false, "violations": [], "rewritten": null },'."\n";
        }
        if (in_array('personality', $enabledChecks)) {
            $examples .= '    "personality": { "required": false, "issues": [], "rewritten": null }'."\n";
        }

        $examples .= '  },'."\n";
        $examples .= '  "final_response": "สินค้า A ราคา 599 บาทค่ะ"'."\n";
        $examples .= "}\n```\n\n";

        // Bad example - fact check fails
        if (in_array('fact_check', $enabledChecks)) {
            $examples .= "## Example 2: Fact Check Fails (Unverified Price)\n";
            $examples .= "User: ราคาสินค้า A เท่าไหร่\n";
            $examples .= "Response: สินค้า A ราคา 299 บาท ลดราคา 50% วันนี้เท่านั้น!\n";
            $examples .= "Issue: Price (299) and discount (50%) not found in Knowledge Base\n";
            $examples .= "Result:\n```json\n";
            $examples .= "{\n";
            $examples .= '  "passed": false,'."\n";
            $examples .= '  "modifications": {'."\n";
            $examples .= '    "fact_check": {'."\n";
            $examples .= '      "required": true,'."\n";
            $examples .= '      "claims_extracted": ["ราคา 299 บาท", "ลดราคา 50%"],'."\n";
            $examples .= '      "unverified_claims": ["ราคา 299 บาท", "ลดราคา 50%"],'."\n";
            $examples .= '      "rewritten": "สำหรับราคาสินค้า A กรุณาติดต่อสอบถามเจ้าหน้าที่โดยตรงค่ะ"'."\n";
            $examples .= '    }'."\n";
            $examples .= '  },'."\n";
            $examples .= '  "final_response": "สำหรับราคาสินค้า A กรุณาติดต่อสอบถามเจ้าหน้าที่โดยตรงค่ะ"'."\n";
            $examples .= "}\n```\n\n";
        }

        // Bad example - policy violation
        if (in_array('policy', $enabledChecks)) {
            $examples .= "## Example 3: Policy Violation (Inappropriate Language)\n";
            $examples .= "User: ทำไมบริการห่วยจัง\n";
            $examples .= "Response: ขอโทษที่บริการของเราห่วย จะปรับปรุงให้ดีขึ้นค่ะ\n";
            $examples .= "Issue: Response uses inappropriate language (ห่วย)\n";
            $examples .= "Result:\n```json\n";
            $examples .= "{\n";
            $examples .= '  "passed": false,'."\n";
            $examples .= '  "modifications": {'."\n";
            $examples .= '    "policy": {'."\n";
            $examples .= '      "required": true,'."\n";
            $examples .= '      "violations": ["ใช้คำไม่สุภาพ (ห่วย)"],'."\n";
            $examples .= '      "rewritten": "ขออภัยที่บริการยังไม่เป็นที่พอใจค่ะ ทีมงานจะปรับปรุงให้ดีขึ้น"'."\n";
            $examples .= '    }'."\n";
            $examples .= '  },'."\n";
            $examples .= '  "final_response": "ขออภัยที่บริการยังไม่เป็นที่พอใจค่ะ ทีมงานจะปรับปรุงให้ดีขึ้น"'."\n";
            $examples .= "}\n```\n\n";
        }

        // Bad example - personality mismatch
        if (in_array('personality', $enabledChecks)) {
            $examples .= "## Example 4: Personality Mismatch (Wrong Tone)\n";
            $examples .= "User: สวัสดีครับ\n";
            $examples .= "Response: Hello! What do you need? (formal/English personality expected Thai)\n";
            $examples .= "Issue: Bot personality is Thai-speaking, friendly. Response is English and abrupt.\n";
            $examples .= "Result:\n```json\n";
            $examples .= "{\n";
            $examples .= '  "passed": false,'."\n";
            $examples .= '  "modifications": {'."\n";
            $examples .= '    "personality": {'."\n";
            $examples .= '      "required": true,'."\n";
            $examples .= '      "issues": ["ใช้ภาษาอังกฤษแทนที่จะเป็นภาษาไทย", "น้ำเสียงห้วนเกินไป"],'."\n";
            $examples .= '      "rewritten": "สวัสดีค่ะ ยินดีให้บริการค่ะ มีอะไรให้ช่วยเหลือไหมคะ?"'."\n";
            $examples .= '    }'."\n";
            $examples .= '  },'."\n";
            $examples .= '  "final_response": "สวัสดีค่ะ ยินดีให้บริการค่ะ มีอะไรให้ช่วยเหลือไหมคะ?"'."\n";
            $examples .= "}\n```\n\n";
        }

        return $examples;
    }

    /**
     * Parse and validate LLM JSON response
     */
    protected function parseResponse(string $rawResponse): array
    {
        $trimmed = trim($rawResponse);
        $json = null;

        // Strategy 1: Extract JSON from markdown code blocks
        if (preg_match('/```(?:json|JSON)?\s*(\{[\s\S]*?\})\s*```/', $trimmed, $matches)) {
            try {
                $json = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Fall through to strategy 2
            }
        }

        // Strategy 2: Extract first complete JSON object
        if ($json === null && preg_match('/(\{[\s\S]*\})/s', $trimmed, $matches)) {
            try {
                $json = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                Log::error('UnifiedCheckService: Invalid JSON response', [
                    'error' => $e->getMessage(),
                    'raw_response' => substr($rawResponse, 0, 500),
                ]);
                throw new \RuntimeException('Invalid JSON response from unified check');
            }
        }

        if ($json === null) {
            Log::error('UnifiedCheckService: No JSON found in response', [
                'raw_response' => substr($rawResponse, 0, 500),
            ]);
            throw new \RuntimeException('Invalid JSON response from unified check');
        }

        // Lenient defaults for missing fields
        $modifications = $json['modifications'] ?? [];

        if (!isset($json['passed']) || !is_bool($json['passed'])) {
            $json['passed'] = $this->inferPassedFromModifications($modifications);
        }

        if (!is_array($modifications)) {
            $modifications = [];
        }
        $json['modifications'] = $modifications;

        if (!isset($json['final_response']) || !is_string($json['final_response']) || empty($json['final_response'])) {
            $fallback = $this->extractLastRewritten($modifications);
            if ($fallback === null) {
                throw new \RuntimeException('Missing or invalid "final_response" field in unified check response');
            }
            $json['final_response'] = $fallback;
        }

        return $json;
    }

    /**
     * Infer passed status from modifications when LLM omits it
     */
    protected function inferPassedFromModifications(array $modifications): bool
    {
        foreach ($modifications as $mod) {
            if (is_array($mod) && ($mod['required'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract the last rewritten text from modifications as fallback
     */
    protected function extractLastRewritten(array $modifications): ?string
    {
        $lastRewritten = null;
        foreach ($modifications as $mod) {
            if (is_array($mod) && !empty($mod['rewritten'])) {
                $lastRewritten = $mod['rewritten'];
            }
        }
        return $lastRewritten;
    }

    /**
     * Filter modifications by confidence threshold.
     * Demotes low-confidence required checks to non-required.
     */
    protected function filterByConfidence(array $modifications, float $threshold = 0.7): array
    {
        foreach ($modifications as $checkType => &$mod) {
            if (!is_array($mod)) continue;
            $confidence = $mod['confidence'] ?? 1.0;
            if (($mod['required'] ?? false) && $confidence < $threshold) {
                Log::info('UnifiedCheck: Low confidence, demoting', [
                    'check' => $checkType,
                    'confidence' => $confidence,
                ]);
                $mod['required'] = false;
                $mod['rewritten'] = null;
            }
        }
        return $modifications;
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
