<?php

namespace App\Services\SecondAI;

use App\Models\Flow;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

/**
 * PolicyCheckService - Verifies AI responses against business policies
 *
 * Extracts policy rules from the system prompt and checks that the
 * AI response doesn't violate any business rules. If violations are
 * found, the response is rewritten to be policy-compliant.
 */
class PolicyCheckService
{
    /**
     * Model to use for policy checking.
     * Set dynamically from Bot Settings in check().
     */
    protected string $model;

    /**
     * Default policy rules if not specified in system prompt.
     */
    protected array $defaultPolicies = [
        'ห้ามพูดถึงคู่แข่งในเชิงลบหรือเปรียบเทียบ',
        'ห้ามให้ส่วนลดหรือโปรโมชั่นที่ไม่มีจริง',
        'ห้ามเปิดเผยข้อมูลภายในบริษัท',
        'ห้ามให้ warranty หรือ guarantee ที่เกินกว่าที่กำหนด',
        'ห้ามรับคำสั่งซื้อหรือยืนยันการจองโดยไม่มีระบบรองรับ',
    ];

    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Check the response for policy compliance.
     *
     * @param string $response The AI-generated response to check
     * @param Flow $flow The flow containing system prompt with policies
     * @param string|null $apiKey Optional API key override
     * @return CheckResult The policy check result
     */
    public function check(
        string $response,
        Flow $flow,
        ?string $apiKey = null
    ): CheckResult {
        // Resolve model from Bot Settings
        $this->model = $flow->bot?->decision_model
            ?: $flow->bot?->primary_chat_model
            ?: throw new \RuntimeException('Bot does not have a model configured. Please set decision_model or primary_chat_model in Bot Settings.');

        try {
            // Step 1: Extract policy rules from system prompt
            $policyRules = $this->extractPolicyRules($flow->system_prompt, $apiKey);

            if (empty($policyRules)) {
                Log::debug('PolicyCheck: No policies found, using defaults');
                $policyRules = $this->defaultPolicies;
            }

            Log::debug('PolicyCheck: Extracted policies', ['count' => count($policyRules)]);

            // Step 2: Check response against policies
            $checkResult = $this->checkAgainstPolicies($response, $policyRules, $apiKey);

            // Step 3: If violations found, rewrite
            if (!empty($checkResult['violations'])) {
                Log::info('PolicyCheck: Found violations', [
                    'count' => count($checkResult['violations']),
                ]);

                $rewrittenResponse = $this->rewritePolicyCompliant(
                    $response,
                    $checkResult['violations'],
                    $policyRules,
                    $apiKey
                );

                return CheckResult::modified(
                    content: $rewrittenResponse,
                    modifications: [
                        'violations' => $checkResult['violations'],
                        'policies_checked' => count($policyRules),
                    ],
                    checkType: 'policy_check'
                );
            }

            Log::debug('PolicyCheck: Response is policy-compliant');
            return CheckResult::passed($response);
        } catch (\Exception $e) {
            Log::error('PolicyCheck: Error during check', [
                'error' => $e->getMessage(),
            ]);

            return CheckResult::failed($response, $e->getMessage());
        }
    }

    /**
     * Extract policy rules from system prompt.
     *
     * Looks for policy-related instructions in the system prompt.
     *
     * @param string $systemPrompt The flow's system prompt
     * @param string|null $apiKey Optional API key override
     * @return array List of policy rules
     */
    protected function extractPolicyRules(string $systemPrompt, ?string $apiKey = null): array
    {
        // If system prompt is short or doesn't seem to have policies, use defaults
        if (strlen($systemPrompt) < 100) {
            return [];
        }

        $prompt = <<<PROMPT
Analyze the following system prompt and extract all business policy rules or restrictions.

Look for:
- Things the AI should NOT do or say
- Restrictions on topics (competitors, pricing, guarantees)
- Required behaviors or disclaimers
- Prohibited information sharing

System Prompt:
{$systemPrompt}

Return ONLY a JSON array of policy rules as strings. If no clear policies found, return empty array [].
Example: ["ห้ามพูดถึงคู่แข่ง", "ต้องแนะนำให้ติดต่อ call center สำหรับคำถามเรื่องราคา"]

JSON array:
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => 'You are a policy extraction assistant. Extract business rules and restrictions from system prompts. Return ONLY valid JSON array.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $this->model,
            temperature: 0.0,
            maxTokens: 1000,
            useFallback: true,
            apiKeyOverride: $apiKey
        );

        $content = trim($result['content']);

        try {
            // Handle potential markdown code blocks
            if (str_contains($content, '```')) {
                preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches);
                $content = $matches[1] ?? $content;
            }

            $policies = json_decode($content, true);

            if (!is_array($policies)) {
                return [];
            }

            return $policies;
        } catch (\Exception $e) {
            Log::warning('PolicyCheck: Failed to parse policies', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check response against policy rules.
     *
     * @param string $response The response to check
     * @param array $policies Policy rules to check against
     * @param string|null $apiKey Optional API key override
     * @return array Check result with violations
     */
    protected function checkAgainstPolicies(
        string $response,
        array $policies,
        ?string $apiKey = null
    ): array {
        $policiesList = implode("\n", array_map(fn ($i, $p) => ($i + 1) . ". {$p}", array_keys($policies), $policies));

        $prompt = <<<PROMPT
Check if the following response violates any of the business policies.

## Business Policies
{$policiesList}

## Response to Check
{$response}

## Instructions
For each policy, determine if it's violated. Return a JSON object with:
- "compliant": boolean (true if all policies respected)
- "violations": array of objects with "policy" (the violated policy) and "reason" (brief explanation)

Example response:
{"compliant": false, "violations": [{"policy": "ห้ามพูดถึงคู่แข่ง", "reason": "Response mentions competitor brand X"}]}

If response is compliant:
{"compliant": true, "violations": []}

JSON:
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => 'You are a policy compliance checker. Analyze responses against business policies and report violations. Return ONLY valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $this->model,
            temperature: 0.0,
            maxTokens: 1000,
            useFallback: true,
            apiKeyOverride: $apiKey
        );

        $content = trim($result['content']);

        try {
            // Handle potential markdown code blocks
            if (str_contains($content, '```')) {
                preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches);
                $content = $matches[1] ?? $content;
            }

            $check = json_decode($content, true);

            if (!is_array($check)) {
                return ['compliant' => true, 'violations' => []];
            }

            return $check;
        } catch (\Exception $e) {
            Log::warning('PolicyCheck: Failed to parse check result', [
                'error' => $e->getMessage(),
            ]);
            return ['compliant' => true, 'violations' => []];
        }
    }

    /**
     * Rewrite response to be policy-compliant.
     *
     * @param string $originalResponse The original response
     * @param array $violations Found policy violations
     * @param array $policies All policy rules
     * @param string|null $apiKey Optional API key override
     * @return string Rewritten policy-compliant response
     */
    protected function rewritePolicyCompliant(
        string $originalResponse,
        array $violations,
        array $policies,
        ?string $apiKey = null
    ): string {
        $violationsList = implode("\n", array_map(
            fn ($v) => "- {$v['policy']}: {$v['reason']}",
            $violations
        ));

        $policiesList = implode("\n", array_map(fn ($p) => "- {$p}", $policies));

        $prompt = <<<PROMPT
Rewrite the following response to fix policy violations while maintaining helpfulness.

## Original Response
{$originalResponse}

## Policy Violations Found
{$violationsList}

## All Business Policies
{$policiesList}

## Instructions
1. Remove or rephrase content that violates policies
2. Keep the response helpful and informative within policy bounds
3. If unable to answer due to policy restrictions, politely redirect to appropriate channel (e.g., call center, website)
4. Maintain the same language as the original response
5. Keep a professional and helpful tone

Rewritten response:
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => 'You are a helpful assistant that rewrites responses to comply with business policies while remaining helpful. Respond in the same language as the input.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $this->model,
            temperature: 0.3,
            maxTokens: 2000,
            useFallback: true,
            apiKeyOverride: $apiKey
        );

        return trim($result['content']);
    }
}
