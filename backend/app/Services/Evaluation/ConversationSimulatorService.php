<?php

namespace App\Services\Evaluation;

use App\Models\Bot;
use App\Models\EvaluationMessage;
use App\Models\EvaluationTestCase;
use App\Models\Flow;
use App\Services\RAGService;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

class ConversationSimulatorService
{
    protected RAGService $ragService;
    protected OpenRouterService $openRouter;
    protected PersonaService $personaService;

    public function __construct(
        RAGService $ragService,
        OpenRouterService $openRouter,
        PersonaService $personaService
    ) {
        $this->ragService = $ragService;
        $this->openRouter = $openRouter;
        $this->personaService = $personaService;
    }

    /**
     * Simulate conversation for a test case
     */
    public function simulateConversation(
        EvaluationTestCase $testCase,
        Bot $bot,
        Flow $flow,
        ?string $apiKey = null
    ): array {
        $testCase->markAsRunning();

        $maxTurns = $testCase->test_type === EvaluationTestCase::TYPE_MULTI_TURN ? 3 : 1;
        $tokensUsed = 0;
        $conversationHistory = [];

        try {
            // Get or create initial user message
            $userMessage = $this->getOrCreateInitialMessage($testCase, $apiKey);

            for ($turn = 1; $turn <= $maxTurns; $turn++) {
                // Store user message if not already stored
                $userMsg = $this->ensureUserMessage($testCase, $turn, $userMessage);
                $conversationHistory[] = ['role' => 'user', 'content' => $userMessage];

                // Get bot response using RAGService
                $response = $this->getBotResponse(
                    bot: $bot,
                    flow: $flow,
                    userMessage: $userMessage,
                    conversationHistory: array_slice($conversationHistory, 0, -1),
                    apiKey: $apiKey
                );

                $tokensUsed += ($response['usage']['prompt_tokens'] ?? 0) +
                               ($response['usage']['completion_tokens'] ?? 0);

                // Store assistant message
                $this->storeAssistantMessage(
                    testCase: $testCase,
                    turnNumber: $turn,
                    content: $response['content'],
                    ragMetadata: $response['rag_metadata'] ?? null,
                    modelMetadata: [
                        'model' => $response['model'] ?? null,
                        'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                        'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                        'latency_ms' => $response['latency_ms'] ?? null,
                    ]
                );

                $conversationHistory[] = ['role' => 'assistant', 'content' => $response['content']];

                // For multi-turn, generate follow-up question
                if ($turn < $maxTurns && $testCase->test_type === EvaluationTestCase::TYPE_MULTI_TURN) {
                    $followUp = $this->generateFollowUpQuestion(
                        testCase: $testCase,
                        conversation: $conversationHistory,
                        apiKey: $apiKey
                    );

                    if ($followUp) {
                        $userMessage = $followUp;
                        $tokensUsed += 100; // Approximate tokens for follow-up generation
                    } else {
                        break; // No follow-up, end conversation
                    }
                }
            }

            return [
                'success' => true,
                'tokens_used' => $tokensUsed,
                'turns' => count($conversationHistory) / 2,
            ];

        } catch (\Exception $e) {
            Log::error("Conversation simulation failed for test case {$testCase->id}: {$e->getMessage()}");
            $testCase->markAsFailed();
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'tokens_used' => $tokensUsed,
            ];
        }
    }

    /**
     * Get or create initial user message for test case
     */
    protected function getOrCreateInitialMessage(EvaluationTestCase $testCase, ?string $apiKey): string
    {
        // Check if initial message already exists
        $existingMessage = $testCase->messages()
            ->where('turn_number', 1)
            ->where('role', 'user')
            ->first();

        if ($existingMessage) {
            return $existingMessage->content;
        }

        // Generate message based on test type
        if ($testCase->test_type === EvaluationTestCase::TYPE_EDGE_CASE ||
            $testCase->test_type === EvaluationTestCase::TYPE_PERSONA_ADHERENCE) {
            // These should already have messages from generation
            return "สวัสดีครับ";
        }

        // For KB-based tests, generate question from source chunks
        return $this->generateQuestionFromTestCase($testCase, $apiKey);
    }

    /**
     * Generate question from test case context
     */
    protected function generateQuestionFromTestCase(EvaluationTestCase $testCase, ?string $apiKey): string
    {
        $persona = $this->personaService->getPersona($testCase->persona_key);
        $topics = $testCase->expected_topics ?? ['general inquiry'];

        $prompt = <<<PROMPT
Generate a natural Thai customer question based on:

Persona: {$persona['name']} ({$persona['style']})
Topics: {$this->formatArray($topics)}

Rules:
- Write in Thai
- Match the persona's communication style
- Keep it concise (1-2 sentences)
- Ask about the specified topics

Output only the question, no explanation.
PROMPT;

        try {
            $response = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: 'anthropic/claude-3-haiku-20240307',
                temperature: 0.7,
                maxTokens: 200,
                apiKeyOverride: $apiKey
            );

            return trim($response['content']);
        } catch (\Exception $e) {
            Log::error("Failed to generate question: {$e->getMessage()}");
            return "สวัสดีครับ ขอสอบถามข้อมูลหน่อยครับ";
        }
    }

    /**
     * Ensure user message exists
     */
    protected function ensureUserMessage(EvaluationTestCase $testCase, int $turn, string $content): EvaluationMessage
    {
        return $testCase->messages()->firstOrCreate(
            [
                'turn_number' => $turn,
                'role' => 'user',
            ],
            [
                'content' => $content,
            ]
        );
    }

    /**
     * Get bot response using existing RAGService
     */
    protected function getBotResponse(
        Bot $bot,
        Flow $flow,
        string $userMessage,
        array $conversationHistory,
        ?string $apiKey = null
    ): array {
        $startTime = microtime(true);

        // Use RAGService for knowledge-augmented response
        $result = $this->ragService->generateResponse(
            bot: $bot,
            userMessage: $userMessage,
            conversationHistory: $conversationHistory,
            flow: $flow,
            apiKeyOverride: $apiKey
        );

        $result['latency_ms'] = (int) ((microtime(true) - $startTime) * 1000);

        return $result;
    }

    /**
     * Store assistant message
     */
    protected function storeAssistantMessage(
        EvaluationTestCase $testCase,
        int $turnNumber,
        string $content,
        ?array $ragMetadata,
        array $modelMetadata
    ): EvaluationMessage {
        return $testCase->messages()->create([
            'turn_number' => $turnNumber,
            'role' => 'assistant',
            'content' => $content,
            'rag_metadata' => $ragMetadata,
            'model_metadata' => $modelMetadata,
        ]);
    }

    /**
     * Generate follow-up question for multi-turn conversation
     */
    protected function generateFollowUpQuestion(
        EvaluationTestCase $testCase,
        array $conversation,
        ?string $apiKey
    ): ?string {
        $persona = $this->personaService->getPersona($testCase->persona_key);

        $conversationText = collect($conversation)->map(function ($msg) {
            $role = $msg['role'] === 'user' ? 'ลูกค้า' : 'Bot';
            return "{$role}: {$msg['content']}";
        })->implode("\n");

        $prompt = <<<PROMPT
Based on this conversation, generate a natural follow-up question from the customer.

## Conversation
{$conversationText}

## Customer Persona
{$persona['name']}: {$persona['style']}

## Instructions
- Generate a follow-up question that continues the conversation naturally
- Match the persona's style
- The question should dig deeper or ask for clarification
- If the conversation seems complete, respond with just: DONE

Output only the follow-up question or DONE.
PROMPT;

        try {
            $response = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: 'anthropic/claude-3-haiku-20240307',
                temperature: 0.7,
                maxTokens: 200,
                apiKeyOverride: $apiKey
            );

            $content = trim($response['content']);
            return $content === 'DONE' ? null : $content;
        } catch (\Exception $e) {
            Log::error("Failed to generate follow-up: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Helper: Format array for prompt
     */
    protected function formatArray(array $items): string
    {
        return implode(', ', $items);
    }
}
