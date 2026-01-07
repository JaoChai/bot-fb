<?php

namespace App\Services\SecondAI;

use App\Models\Flow;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

/**
 * PersonalityCheckService - Verifies AI responses match brand personality
 *
 * Extracts brand guidelines from the system prompt and checks that the
 * AI response maintains consistent tone and personality. If tone issues
 * are found, the response is rewritten to match brand guidelines.
 */
class PersonalityCheckService
{
    /**
     * Model to use for personality checking.
     */
    protected string $model = 'openai/gpt-4o-mini';

    /**
     * Default brand guidelines if not specified in system prompt.
     */
    protected array $defaultGuidelines = [
        'tone' => 'professional but friendly',
        'language_style' => 'polite, using appropriate honorifics',
        'emoji_usage' => 'minimal, only when appropriate',
        'formality' => 'formal but approachable',
    ];

    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Check the response for brand personality consistency.
     *
     * @param string $response The AI-generated response to check
     * @param Flow $flow The flow containing system prompt with brand guidelines
     * @param string|null $apiKey Optional API key override
     * @return CheckResult The personality check result
     */
    public function check(
        string $response,
        Flow $flow,
        ?string $apiKey = null
    ): CheckResult {
        try {
            // Step 1: Extract brand guidelines from system prompt
            $brandGuidelines = $this->extractBrandGuidelines($flow->system_prompt, $apiKey);

            if (empty($brandGuidelines)) {
                Log::debug('PersonalityCheck: No brand guidelines found, using defaults');
                $brandGuidelines = $this->defaultGuidelines;
            }

            Log::debug('PersonalityCheck: Extracted guidelines', [
                'keys' => array_keys($brandGuidelines),
            ]);

            // Step 2: Check response against brand guidelines
            $checkResult = $this->checkAgainstGuidelines($response, $brandGuidelines, $apiKey);

            // Step 3: If issues found, rewrite
            if (!$checkResult['matches']) {
                Log::info('PersonalityCheck: Tone issues found', [
                    'issues' => $checkResult['issues'] ?? [],
                ]);

                $rewrittenResponse = $checkResult['rewritten'] ?? $this->rewriteWithBrandPersonality(
                    $response,
                    $brandGuidelines,
                    $checkResult['issues'] ?? [],
                    $apiKey
                );

                return CheckResult::modified(
                    content: $rewrittenResponse,
                    modifications: [
                        'issues' => $checkResult['issues'] ?? [],
                        'guidelines_checked' => array_keys($brandGuidelines),
                    ],
                    checkType: 'personality_check'
                );
            }

            Log::debug('PersonalityCheck: Response matches brand personality');
            return CheckResult::passed($response);
        } catch (\Exception $e) {
            Log::error('PersonalityCheck: Error during check', [
                'error' => $e->getMessage(),
            ]);

            return CheckResult::failed($response, $e->getMessage());
        }
    }

    /**
     * Extract brand guidelines from system prompt.
     *
     * @param string $systemPrompt The flow's system prompt
     * @param string|null $apiKey Optional API key override
     * @return array Brand guidelines as key-value pairs
     */
    protected function extractBrandGuidelines(string $systemPrompt, ?string $apiKey = null): array
    {
        // If system prompt is short, use defaults
        if (strlen($systemPrompt) < 100) {
            return [];
        }

        $prompt = <<<PROMPT
Analyze the following system prompt and extract brand personality guidelines.

Look for:
- Tone and voice instructions (friendly, professional, casual, formal)
- Language style preferences
- Emoji usage guidelines
- Specific phrases or words to use/avoid
- Character or persona description
- Communication style requirements

System Prompt:
{$systemPrompt}

Return a JSON object with extracted guidelines. Use these keys when applicable:
- "tone": Overall tone (e.g., "professional but friendly")
- "language_style": Writing style preferences
- "emoji_usage": Emoji guidelines
- "formality": Formal/informal preference
- "persona": Character or persona description
- "prohibited_phrases": Phrases to avoid
- "required_phrases": Phrases to include

If no clear guidelines found, return empty object {}.

JSON object:
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => 'You are a brand guideline extraction assistant. Extract personality and tone guidelines from system prompts. Return ONLY valid JSON object.'],
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

            $guidelines = json_decode($content, true);

            if (!is_array($guidelines)) {
                return [];
            }

            return $guidelines;
        } catch (\Exception $e) {
            Log::warning('PersonalityCheck: Failed to parse guidelines', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check response against brand guidelines.
     *
     * @param string $response The response to check
     * @param array $guidelines Brand guidelines
     * @param string|null $apiKey Optional API key override
     * @return array Check result with matches status and issues
     */
    protected function checkAgainstGuidelines(
        string $response,
        array $guidelines,
        ?string $apiKey = null
    ): array {
        $guidelinesJson = json_encode($guidelines, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
Check if the following response matches the brand personality guidelines.

## Brand Guidelines
{$guidelinesJson}

## Response to Check
{$response}

## Instructions
Analyze the response against each guideline. Return a JSON object with:
- "matches": boolean (true if response matches brand personality)
- "issues": array of strings describing what doesn't match
- "rewritten": string (rewritten response if matches is false, null if matches is true)

If response matches the brand:
{"matches": true, "issues": [], "rewritten": null}

If response doesn't match:
{"matches": false, "issues": ["Tone is too casual", "Missing polite particles"], "rewritten": "..improved response.."}

Important: The rewritten response should maintain the same information but adjust tone/style to match guidelines.

JSON:
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => 'You are a brand consistency checker. Analyze responses against brand personality guidelines. If rewriting, respond in the same language as the input. Return ONLY valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $this->model,
            temperature: 0.3,
            maxTokens: 2000,
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
                return ['matches' => true, 'issues' => [], 'rewritten' => null];
            }

            return $check;
        } catch (\Exception $e) {
            Log::warning('PersonalityCheck: Failed to parse check result', [
                'error' => $e->getMessage(),
            ]);
            return ['matches' => true, 'issues' => [], 'rewritten' => null];
        }
    }

    /**
     * Rewrite response to match brand personality.
     *
     * Fallback method if the combined check didn't provide a rewrite.
     *
     * @param string $originalResponse The original response
     * @param array $guidelines Brand guidelines
     * @param array $issues Found issues
     * @param string|null $apiKey Optional API key override
     * @return string Rewritten response matching brand personality
     */
    protected function rewriteWithBrandPersonality(
        string $originalResponse,
        array $guidelines,
        array $issues,
        ?string $apiKey = null
    ): string {
        $guidelinesJson = json_encode($guidelines, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $issuesList = implode("\n", array_map(fn ($i) => "- {$i}", $issues));

        $prompt = <<<PROMPT
Rewrite the following response to match the brand personality guidelines.

## Original Response
{$originalResponse}

## Brand Guidelines
{$guidelinesJson}

## Issues to Fix
{$issuesList}

## Instructions
1. Adjust tone and style to match brand guidelines
2. Keep all factual information the same
3. Maintain the same language as the original response
4. Apply appropriate formality and word choices
5. Add or remove emojis based on guidelines

Rewritten response:
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'system', 'content' => 'You are a brand voice specialist. Rewrite responses to match brand personality while keeping the same information. Respond in the same language as the input.'],
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
