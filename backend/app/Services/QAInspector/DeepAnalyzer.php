<?php

namespace App\Services\QAInspector;

use App\Models\Bot;
use App\Models\QAEvaluationLog;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

class DeepAnalyzer
{
    public function __construct(
        private QAInspectorService $qaInspectorService,
        private OpenRouterService $openRouterService,
    ) {}

    /**
     * Analyze a flagged evaluation log to identify root cause
     */
    public function analyze(QAEvaluationLog $log, Bot $bot): ?array
    {
        $startTime = microtime(true);

        if (!$log->is_flagged) {
            return null;
        }

        $models = $this->qaInspectorService->getModelsForLayer($bot, 'analysis');

        $prompt = $this->buildAnalysisPrompt($log);

        try {
            $result = $this->callModel($models['primary'], $prompt);
            $modelUsed = $models['primary'];

            if (!$result && $models['fallback']) {
                Log::channel('qa_inspector')->warning('DeepAnalyzer primary model failed, trying fallback', [
                    'bot_id' => $bot->id,
                    'log_id' => $log->id,
                    'primary_model' => $models['primary'],
                    'fallback_model' => $models['fallback'],
                ]);
                $result = $this->callModel($models['fallback'], $prompt);
                $modelUsed = $models['fallback'];
            }

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            if ($result) {
                // Update the evaluation log with analysis
                $log->update([
                    'issue_details' => $result,
                ]);

                Log::channel('qa_inspector')->info('Deep analysis completed', [
                    'bot_id' => $bot->id,
                    'log_id' => $log->id,
                    'issue_type' => $log->issue_type,
                    'model_used' => $modelUsed,
                    'root_cause' => $result['root_cause'] ?? null,
                    'severity' => $result['severity'] ?? null,
                    'duration_ms' => $durationMs,
                ]);
            } else {
                Log::channel('qa_inspector')->warning('Deep analysis returned no result', [
                    'bot_id' => $bot->id,
                    'log_id' => $log->id,
                    'models_attempted' => [$models['primary'], $models['fallback']],
                    'duration_ms' => $durationMs,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('qa_inspector')->error('Deep analysis failed', [
                'bot_id' => $bot->id,
                'log_id' => $log->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'duration_ms' => $durationMs,
            ]);
            return null;
        }
    }

    private function buildAnalysisPrompt(QAEvaluationLog $log): string
    {
        $systemPrompt = $log->system_prompt_used ?? 'No system prompt available';

        return <<<PROMPT
You are analyzing a bot response that has been flagged as problematic.
Identify the ROOT CAUSE of the issue and which section of the system prompt is responsible.

## System Prompt Used:
{$systemPrompt}

## User Question:
{$log->user_question}

## Bot Response:
{$log->bot_response}

## Evaluation Scores:
- Answer Relevancy: {$log->answer_relevancy}
- Faithfulness: {$log->faithfulness}
- Role Adherence: {$log->role_adherence}
- Context Precision: {$log->context_precision}
- Task Completion: {$log->task_completion}
- Overall Score: {$log->overall_score}

## Detected Issue Type: {$log->issue_type}

Analyze this and return a JSON object:
{
  "analysis_model": "your-model-name",
  "root_cause": "Brief explanation of what went wrong (1-2 sentences in Thai)",
  "prompt_section_identified": "Name of the section in system prompt that caused this (e.g., 'STEP 2 CONFIRM', 'PRICING RULES')",
  "severity": "low|medium|high",
  "confidence": 0.85,
  "suggested_fix": "Brief suggestion for how to fix this in Thai"
}
PROMPT;
    }

    private function callModel(string $modelId, string $prompt): ?array
    {
        try {
            $response = $this->openRouterService->chat(
                messages: [
                    ['role' => 'user', 'content' => $prompt],
                ],
                model: $modelId,
                temperature: 0.2,
                maxTokens: 800,
                useFallback: false,
            );

            $content = $response['content'] ?? '';

            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $result = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $result['analysis_timestamp'] = now()->toISOString();
                    return $result;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::channel('qa_inspector')->error('DeepAnalyzer model call failed', [
                'model' => $modelId,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            return null;
        }
    }
}
