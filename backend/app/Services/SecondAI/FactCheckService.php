<?php

namespace App\Services\SecondAI;

use App\Models\Flow;
use App\Services\HybridSearchService;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

/**
 * FactCheckService - Verifies AI responses against Knowledge Base
 *
 * Extracts factual claims from the AI response and verifies each one
 * against the associated Knowledge Base using hybrid search. If any
 * claims cannot be verified, the response is rewritten without them.
 */
class FactCheckService
{
    /**
     * Minimum similarity score to consider a claim verified.
     */
    protected float $verificationThreshold = 0.6;

    /**
     * Number of KB results to fetch per claim for verification.
     */
    protected int $topK = 3;

    /**
     * Model to use for claim extraction and rewriting.
     * Set dynamically from Bot Settings in check().
     */
    protected string $model;

    public function __construct(
        protected HybridSearchService $hybridSearch,
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Check the response for factual accuracy against Knowledge Base.
     *
     * @param  string  $response  The AI-generated response to verify
     * @param  Flow  $flow  The flow containing KB configuration
     * @param  string  $userMessage  The original user message (for context)
     * @param  string|null  $apiKey  Optional API key override
     * @return CheckResult The verification result
     */
    public function check(
        string $response,
        Flow $flow,
        string $userMessage,
        ?string $apiKey = null,
        ?int $timeout = null,
        ?string $fallbackModel = null
    ): CheckResult {
        // Resolve model from Bot Settings
        $this->model = $flow->bot?->decision_model
            ?: $flow->bot?->primary_chat_model
            ?: throw new \RuntimeException('Bot does not have a model configured. Please set decision_model or primary_chat_model in Bot Settings.');

        // Skip if flow has no knowledge bases
        if (! $flow->relationLoaded('knowledgeBases')) {
            $flow->load('knowledgeBases');
        }

        if ($flow->knowledgeBases->isEmpty()) {
            Log::debug('FactCheck: Skipped - no knowledge bases attached to flow');

            return CheckResult::passed($response);
        }

        try {
            // Step 1: Extract factual claims from response
            $claims = $this->extractClaims($response, $apiKey, $timeout, $fallbackModel);

            if (empty($claims)) {
                Log::debug('FactCheck: No factual claims found in response');

                return CheckResult::passed($response);
            }

            Log::debug('FactCheck: Extracted claims', ['count' => count($claims)]);

            // Step 2: Verify each claim against KB
            $verifiedClaims = $this->verifyClaims($claims, $flow, $apiKey);

            // Step 3: Check if any claims failed verification
            $unverifiedClaims = collect($verifiedClaims)
                ->where('has_evidence', false)
                ->all();

            if (empty($unverifiedClaims)) {
                Log::debug('FactCheck: All claims verified');

                return CheckResult::passed($response);
            }

            Log::info('FactCheck: Found unverified claims', [
                'total' => count($claims),
                'unverified' => count($unverifiedClaims),
            ]);

            // Step 4: Rewrite response without unverified claims
            $rewrittenResponse = $this->rewriteWithoutUnverifiedClaims(
                $response,
                $verifiedClaims,
                $userMessage,
                $apiKey,
                $timeout,
                $fallbackModel
            );

            return CheckResult::modified(
                content: $rewrittenResponse,
                modifications: [
                    'unverified_claims' => array_map(fn ($c) => $c['claim'], $unverifiedClaims),
                    'total_claims' => count($claims),
                    'verified_claims' => count($claims) - count($unverifiedClaims),
                ],
                checkType: 'fact_check'
            );
        } catch (\Exception $e) {
            Log::error('FactCheck: Error during verification', [
                'error' => $e->getMessage(),
            ]);

            // Return original response on error (graceful fallback)
            return CheckResult::failed($response, $e->getMessage());
        }
    }

    /**
     * Extract factual claims from the AI response.
     *
     * Uses LLM to identify specific factual statements that can be verified.
     *
     * @param  string  $response  The response to analyze
     * @param  string|null  $apiKey  Optional API key override
     * @return array List of extracted claims
     */
    protected function extractClaims(string $response, ?string $apiKey = null, ?int $timeout = null, ?string $fallbackModel = null): array
    {
        $prompt = <<<PROMPT
Analyze the following response and extract all factual claims that can be verified against a knowledge base.

A factual claim is:
- A specific statement about a product, service, policy, price, feature, or process
- NOT opinions, greetings, or general statements
- NOT questions or suggestions

Response to analyze:
{$response}

Return ONLY a JSON array of strings, each being a factual claim. If no factual claims found, return empty array [].
Example: ["Product X costs 500 baht", "Free shipping for orders over 1000 baht"]

JSON array:
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => 'You are a fact extraction assistant. Extract factual claims from text and return them as a JSON array. Return ONLY valid JSON, no additional text.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $this->model,
            temperature: 0.0,
            maxTokens: 1000,
            useFallback: true,
            apiKeyOverride: $apiKey,
            fallbackModelOverride: $fallbackModel,
            timeout: $timeout,
            reasoning: ['effort' => config('rag.second_ai.reasoning_effort', 'low')],
        );

        $content = trim($result['content']);

        // Try to parse JSON from response
        try {
            // Handle potential markdown code blocks
            if (str_contains($content, '```')) {
                preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches);
                $content = $matches[1] ?? $content;
            }

            $claims = json_decode($content, true);

            if (! is_array($claims)) {
                Log::warning('FactCheck: Invalid claims format', ['content' => $content]);

                return [];
            }

            return $claims;
        } catch (\Exception $e) {
            Log::warning('FactCheck: Failed to parse claims', [
                'error' => $e->getMessage(),
                'content' => $content,
            ]);

            return [];
        }
    }

    /**
     * Verify claims against the Knowledge Base.
     *
     * @param  array  $claims  List of claims to verify
     * @param  Flow  $flow  Flow with knowledge bases
     * @param  string|null  $apiKey  Optional API key override
     * @return array Claims with verification status and evidence
     */
    protected function verifyClaims(array $claims, Flow $flow, ?string $apiKey = null): array
    {
        $kbConfigs = $flow->knowledgeBases->map(fn ($kb) => [
            'id' => $kb->id,
            'kb_top_k' => $kb->pivot->kb_top_k ?? $this->topK,
            'kb_similarity_threshold' => $kb->pivot->kb_similarity_threshold ?? $this->verificationThreshold,
        ])->toArray();

        $verifiedClaims = [];

        foreach ($claims as $claim) {
            // Search KB for evidence supporting this claim
            $evidence = $this->hybridSearch->searchMultiple(
                kbConfigs: $kbConfigs,
                query: $claim,
                totalLimit: $this->topK,
                apiKey: $apiKey
            );

            // Check if we found relevant evidence
            $hasEvidence = $evidence->isNotEmpty() &&
                $evidence->first()['similarity'] >= $this->verificationThreshold;

            $verifiedClaims[] = [
                'claim' => $claim,
                'has_evidence' => $hasEvidence,
                'evidence' => $hasEvidence ? $evidence->take(2)->pluck('content')->toArray() : [],
                'top_score' => $evidence->isNotEmpty() ? $evidence->first()['similarity'] : 0,
            ];

            Log::debug('FactCheck: Claim verification', [
                'claim' => substr($claim, 0, 100),
                'has_evidence' => $hasEvidence,
                'top_score' => $evidence->isNotEmpty() ? $evidence->first()['similarity'] : 0,
            ]);
        }

        return $verifiedClaims;
    }

    /**
     * Rewrite response without unverified claims.
     *
     * @param  string  $originalResponse  The original response
     * @param  array  $verifiedClaims  Claims with verification status
     * @param  string  $userMessage  Original user message for context
     * @param  string|null  $apiKey  Optional API key override
     * @return string Rewritten response
     */
    protected function rewriteWithoutUnverifiedClaims(
        string $originalResponse,
        array $verifiedClaims,
        string $userMessage,
        ?string $apiKey = null,
        ?int $timeout = null,
        ?string $fallbackModel = null
    ): string {
        $unverifiedList = collect($verifiedClaims)
            ->where('has_evidence', false)
            ->pluck('claim')
            ->implode("\n- ");

        $verifiedList = collect($verifiedClaims)
            ->where('has_evidence', true)
            ->map(fn ($c) => $c['claim'].' (evidence: '.implode('; ', array_slice($c['evidence'], 0, 1)).')')
            ->implode("\n- ");

        $prompt = <<<PROMPT
Rewrite the following response to remove or qualify unverified claims while keeping verified information.

## Original User Question
{$userMessage}

## Original Response
{$originalResponse}

## Unverified Claims (REMOVE or qualify with "ข้อมูลนี้อาจไม่แม่นยำ")
- {$unverifiedList}

## Verified Claims (KEEP these)
- {$verifiedList}

## Instructions
1. Remove specific unverified claims or add a qualifier like "ข้อมูลนี้อาจไม่แม่นยำ" or "กรุณาตรวจสอบข้อมูลอีกครั้ง"
2. Keep all verified claims as-is
3. Maintain the same helpful tone and structure
4. If removing claims makes the response unhelpful, suggest the user contact support for accurate information
5. Keep the response in the same language as the original

Rewritten response:
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => 'You are a helpful assistant that rewrites responses to remove unverified information while maintaining helpfulness. Respond in the same language as the input.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $this->model,
            temperature: 0.3,
            maxTokens: 2000,
            useFallback: true,
            apiKeyOverride: $apiKey,
            fallbackModelOverride: $fallbackModel,
            timeout: $timeout,
            reasoning: ['effort' => config('rag.second_ai.reasoning_effort', 'low')],
        );

        return trim($result['content']);
    }
}
