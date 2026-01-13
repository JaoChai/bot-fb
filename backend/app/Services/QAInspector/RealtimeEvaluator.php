<?php

namespace App\Services\QAInspector;

use App\Models\Bot;
use App\Models\Message;
use App\Models\QAEvaluationLog;
use App\Models\User;
use App\Notifications\QALowScoreAlert;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

class RealtimeEvaluator
{
    /**
     * Score threshold for critical alerts (below this triggers immediate notification)
     */
    private const CRITICAL_SCORE_THRESHOLD = 0.5;

    public function __construct(
        private QAInspectorService $qaInspectorService,
        private OpenRouterService $openRouterService,
    ) {}

    /**
     * Evaluate a bot response and store results
     */
    public function evaluate(Message $botResponse, Bot $bot): ?QAEvaluationLog
    {
        $startTime = microtime(true);

        try {
            // Get the user's question (previous message in conversation)
            $userQuestion = $this->getUserQuestion($botResponse);
            if (!$userQuestion) {
                Log::channel('qa_inspector')->warning('No user question found for message', [
                    'bot_id' => $bot->id,
                    'message_id' => $botResponse->id,
                    'conversation_id' => $botResponse->conversation_id,
                ]);
                return null;
            }

            // Get model configuration
            $models = $this->qaInspectorService->getModelsForLayer($bot, 'realtime');

            // Get flow and system prompt
            $flow = $botResponse->conversation?->flow;
            $systemPrompt = $flow?->system_prompt ?? $bot->system_prompt ?? '';

            // Get KB chunks used (if available from message metadata)
            $kbChunks = $botResponse->kb_chunks_used ?? [];

            // Build evaluation prompt
            $evaluationResult = $this->runEvaluation(
                userQuestion: $userQuestion->content,
                botResponse: $botResponse->content,
                systemPrompt: $systemPrompt,
                kbChunks: $kbChunks,
                models: $models,
            );

            if (!$evaluationResult) {
                Log::channel('qa_inspector')->warning('Evaluation returned no result', [
                    'bot_id' => $bot->id,
                    'message_id' => $botResponse->id,
                    'models_attempted' => [$models['primary'], $models['fallback']],
                ]);
                return null;
            }

            // Calculate overall score
            $overallScore = $this->qaInspectorService->calculateOverallScore($evaluationResult['scores']);

            // Determine if should be flagged
            $threshold = $this->qaInspectorService->getThreshold($bot);
            $isFlagged = $overallScore < $threshold;

            // Categorize issue if flagged
            $issueType = $isFlagged
                ? $this->qaInspectorService->categorizeIssue($evaluationResult['scores'], $threshold)
                : null;

            // Create evaluation log
            $evaluationLog = QAEvaluationLog::create([
                'bot_id' => $bot->id,
                'conversation_id' => $botResponse->conversation_id,
                'message_id' => $botResponse->id,
                'flow_id' => $flow?->id,
                'answer_relevancy' => $evaluationResult['scores']['answer_relevancy'] ?? null,
                'faithfulness' => $evaluationResult['scores']['faithfulness'] ?? null,
                'role_adherence' => $evaluationResult['scores']['role_adherence'] ?? null,
                'context_precision' => $evaluationResult['scores']['context_precision'] ?? null,
                'task_completion' => $evaluationResult['scores']['task_completion'] ?? null,
                'overall_score' => $overallScore,
                'is_flagged' => $isFlagged,
                'issue_type' => $issueType,
                'issue_details' => $isFlagged ? ['needs_analysis' => true] : null,
                'user_question' => $userQuestion->content,
                'bot_response' => $botResponse->content,
                'system_prompt_used' => $systemPrompt,
                'kb_chunks_used' => $kbChunks,
                'model_metadata' => [
                    'model_used' => $evaluationResult['model_used'],
                    'tokens_used' => $evaluationResult['tokens_used'] ?? 0,
                    'cost_estimate' => $evaluationResult['cost_estimate'] ?? 0,
                ],
                'evaluated_at' => now(),
            ]);

            // Calculate duration
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            // Log successful evaluation
            Log::channel('qa_inspector')->info('Evaluation completed', [
                'bot_id' => $bot->id,
                'evaluation_id' => $evaluationLog->id,
                'conversation_id' => $evaluationLog->conversation_id,
                'overall_score' => $overallScore,
                'is_flagged' => $isFlagged,
                'issue_type' => $issueType,
                'model_used' => $evaluationResult['model_used'],
                'tokens_used' => $evaluationResult['tokens_used'] ?? 0,
                'cost_estimate' => $evaluationResult['cost_estimate'] ?? 0,
                'duration_ms' => $durationMs,
            ]);

            // Handle critical low score alerts
            if ($overallScore < self::CRITICAL_SCORE_THRESHOLD) {
                $this->handleCriticalScore($evaluationLog, $bot, $overallScore, $issueType);
            }

            return $evaluationLog;

        } catch (\Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('qa_inspector')->error('Evaluation failed', [
                'bot_id' => $bot->id,
                'message_id' => $botResponse->id,
                'conversation_id' => $botResponse->conversation_id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'duration_ms' => $durationMs,
            ]);

            return null;
        }
    }

    /**
     * Get the user's question that preceded the bot response
     */
    private function getUserQuestion(Message $botResponse): ?Message
    {
        return Message::where('conversation_id', $botResponse->conversation_id)
            ->where('id', '<', $botResponse->id)
            ->where('sender', 'user')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Run the actual evaluation using AI model
     */
    private function runEvaluation(
        string $userQuestion,
        string $botResponse,
        string $systemPrompt,
        array $kbChunks,
        array $models,
    ): ?array {
        $evaluationPrompt = $this->buildEvaluationPrompt(
            $userQuestion,
            $botResponse,
            $systemPrompt,
            $kbChunks
        );

        // Try primary model first
        $result = $this->callModel($models['primary'], $evaluationPrompt);

        // Fallback if primary fails
        if (!$result && $models['fallback']) {
            Log::channel('qa_inspector')->warning('Primary model failed, trying fallback', [
                'primary_model' => $models['primary'],
                'fallback_model' => $models['fallback'],
            ]);
            $result = $this->callModel($models['fallback'], $evaluationPrompt);
        }

        return $result;
    }

    /**
     * Build the evaluation prompt for the AI model
     */
    private function buildEvaluationPrompt(
        string $userQuestion,
        string $botResponse,
        string $systemPrompt,
        array $kbChunks,
    ): string {
        $kbContext = !empty($kbChunks)
            ? "Knowledge Base Context:\n" . implode("\n---\n", array_column($kbChunks, 'content'))
            : "No knowledge base context provided.";

        return <<<PROMPT
You are a QA evaluator. Evaluate the bot's response based on the following criteria.
Return a JSON object with scores from 0.0 to 1.0 for each metric.

## System Prompt Used:
{$systemPrompt}

## {$kbContext}

## User Question:
{$userQuestion}

## Bot Response:
{$botResponse}

## Evaluation Criteria:
1. **answer_relevancy** (0.0-1.0): How relevant is the response to the user's question?
2. **faithfulness** (0.0-1.0): Is the response factually accurate and faithful to the knowledge base?
3. **role_adherence** (0.0-1.0): Does the response follow the persona and tone defined in the system prompt?
4. **context_precision** (0.0-1.0): Does the response use the correct context from the knowledge base?
5. **task_completion** (0.0-1.0): Did the response successfully complete the user's intended task?

Return ONLY a JSON object in this exact format:
{
  "answer_relevancy": 0.85,
  "faithfulness": 0.90,
  "role_adherence": 0.80,
  "context_precision": 0.75,
  "task_completion": 0.88
}
PROMPT;
    }

    /**
     * Call the AI model for evaluation
     */
    private function callModel(string $modelId, string $prompt): ?array
    {
        try {
            $response = $this->openRouterService->chat(
                messages: [
                    ['role' => 'user', 'content' => $prompt],
                ],
                model: $modelId,
                temperature: 0.1,
                maxTokens: 500,
                useFallback: false,
            );

            // Parse JSON response
            $content = $response['content'] ?? '';

            // Extract JSON from response
            if (preg_match('/\{[^{}]*\}/', $content, $matches)) {
                $scores = json_decode($matches[0], true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return [
                        'scores' => $scores,
                        'model_used' => $modelId,
                        'tokens_used' => $response['usage']['total_tokens'] ?? 0,
                        'cost_estimate' => $this->estimateCost($modelId, $response['usage'] ?? []),
                    ];
                }
            }

            Log::channel('qa_inspector')->warning('Failed to parse evaluation response', [
                'model' => $modelId,
                'response_preview' => mb_substr($content, 0, 200),
            ]);
            return null;

        } catch (\Exception $e) {
            Log::channel('qa_inspector')->error('Model call failed', [
                'model' => $modelId,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            return null;
        }
    }

    /**
     * Estimate cost based on model and token usage
     */
    private function estimateCost(string $modelId, array $usage): float
    {
        // Approximate costs per 1M tokens (input + output averaged)
        $costPer1M = match (true) {
            str_contains($modelId, 'gemini') => 0.15,
            str_contains($modelId, 'gpt-4o-mini') => 0.30,
            str_contains($modelId, 'claude-sonnet') => 3.00,
            str_contains($modelId, 'claude-opus') => 15.00,
            default => 1.00,
        };

        $totalTokens = ($usage['total_tokens'] ?? 0);
        return ($totalTokens / 1_000_000) * $costPer1M;
    }

    /**
     * Handle critical low score alert
     *
     * When a score falls below the critical threshold (0.5), log a warning
     * and send an in-app notification if alerts are enabled.
     */
    private function handleCriticalScore(
        QAEvaluationLog $evaluationLog,
        Bot $bot,
        float $score,
        ?string $issueType,
    ): void {
        // Always log critical scores
        Log::channel('qa_inspector')->warning('Critical low score detected', [
            'bot_id' => $bot->id,
            'evaluation_id' => $evaluationLog->id,
            'conversation_id' => $evaluationLog->conversation_id,
            'score' => $score,
            'issue_type' => $issueType,
            'threshold' => self::CRITICAL_SCORE_THRESHOLD,
        ]);

        // Check if in-app alerts are enabled
        $notifications = $this->qaInspectorService->getNotificationSettings($bot);

        if (empty($notifications['alert'])) {
            return;
        }

        // Send notification to bot owner
        $user = $bot->user;
        if (!$user instanceof User) {
            return;
        }

        try {
            $user->notify(new QALowScoreAlert($evaluationLog, $bot, $score, $issueType));

            Log::channel('qa_inspector')->info('Low score alert sent', [
                'bot_id' => $bot->id,
                'user_id' => $user->id,
                'evaluation_id' => $evaluationLog->id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('qa_inspector')->error('Failed to send low score alert', [
                'bot_id' => $bot->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
        }
    }
}
