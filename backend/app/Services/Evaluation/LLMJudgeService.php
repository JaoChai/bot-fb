<?php

namespace App\Services\Evaluation;

use App\Models\EvaluationTestCase;
use App\Models\Flow;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

class LLMJudgeService
{
    protected OpenRouterService $openRouter;

    protected ModelTierSelector $tierSelector;

    protected string $defaultJudgeModel = 'anthropic/claude-3.5-sonnet';

    public function __construct(
        OpenRouterService $openRouter,
        ModelTierSelector $tierSelector
    ) {
        $this->openRouter = $openRouter;
        $this->tierSelector = $tierSelector;
    }

    /**
     * Evaluate a single test case
     */
    public function evaluateTestCase(
        EvaluationTestCase $testCase,
        Flow $flow,
        ?string $apiKey = null
    ): array {
        $tokensUsed = 0;

        // Get conversation from test case
        $conversation = $testCase->getConversation();
        $messages = $testCase->messages()->orderBy('turn_number')->get();

        // Get system prompt for context
        $systemPrompt = $flow->system_prompt ?? $flow->bot->system_prompt ?? '';

        // Collect RAG context from assistant messages
        $ragContext = $messages
            ->where('role', 'assistant')
            ->pluck('rag_metadata')
            ->filter()
            ->flatMap(fn ($meta) => $meta['chunks'] ?? [])
            ->toArray();

        $scores = [];
        $feedback = [];
        $modelMetadata = [];

        // Evaluate each metric
        $metrics = [
            'answer_relevancy' => fn () => $this->evaluateAnswerRelevancy($conversation, $apiKey),
            'faithfulness' => fn () => $this->evaluateFaithfulness($conversation, $ragContext, $apiKey),
            'role_adherence' => fn () => $this->evaluateRoleAdherence($conversation, $systemPrompt, $apiKey),
            'context_precision' => fn () => $this->evaluateContextPrecision($testCase, $ragContext, $apiKey),
            'task_completion' => fn () => $this->evaluateTaskCompletion($conversation, $testCase, $apiKey),
        ];

        foreach ($metrics as $metric => $evaluator) {
            try {
                $result = $evaluator();
                $scores[$metric] = $result['score'];
                $feedback[$metric] = $result['reasoning'];
                $tokensUsed += $result['tokens_used'] ?? 0;

                // Store model metadata for cost tracking
                if (isset($result['model_metadata'])) {
                    $modelMetadata[$metric] = $result['model_metadata'];
                }
            } catch (\Exception $e) {
                Log::error("Failed to evaluate {$metric} for test case {$testCase->id}: {$e->getMessage()}");
                $scores[$metric] = null;
                $feedback[$metric] = "Evaluation failed: {$e->getMessage()}";
            }
        }

        // Update test case with scores
        $testCase->markAsCompleted($scores, $feedback);

        // Store model metadata in test case for cost tracking
        if (! empty($modelMetadata)) {
            $testCase->metadata = array_merge($testCase->metadata ?? [], [
                'model_tiers' => $modelMetadata,
            ]);
            $testCase->save();
        }

        return [
            'scores' => $scores,
            'feedback' => $feedback,
            'tokens_used' => $tokensUsed,
            'model_metadata' => $modelMetadata,
        ];
    }

    /**
     * Evaluate Answer Relevancy: Does the response directly address the question?
     */
    protected function evaluateAnswerRelevancy(array $conversation, ?string $apiKey): array
    {
        $conversationText = $this->formatConversation($conversation);

        $prompt = <<<PROMPT
คุณเป็นผู้ประเมินคุณภาพ AI Chatbot ให้ประเมินว่าคำตอบของ Bot ตอบตรงคำถามของลูกค้าหรือไม่

## บทสนทนา
{$conversationText}

## เกณฑ์การประเมิน Answer Relevancy
1.0 = คำตอบตรงคำถาม ครบถ้วน เข้าใจง่าย
0.8 = คำตอบตรงคำถามส่วนใหญ่ อาจขาดบางรายละเอียด
0.6 = คำตอบเกี่ยวข้องแต่ไม่ตรงคำถามทั้งหมด
0.4 = คำตอบเกี่ยวข้องบางส่วนเท่านั้น
0.2 = คำตอบแทบไม่เกี่ยวข้อง
0.0 = คำตอบไม่ตรงคำถามเลย

## รูปแบบ Output (JSON)
{
  "score": 0.0-1.0,
  "reasoning": "เหตุผลสั้นๆ ภาษาไทย"
}
PROMPT;

        return $this->callJudge($prompt, 'answer_relevancy', $apiKey);
    }

    /**
     * Evaluate Faithfulness: Is the response grounded in KB without hallucination?
     */
    protected function evaluateFaithfulness(array $conversation, array $ragContext, ?string $apiKey): array
    {
        $conversationText = $this->formatConversation($conversation);
        $contextText = empty($ragContext)
            ? 'ไม่มีข้อมูลจาก Knowledge Base'
            : implode("\n---\n", array_map(fn ($c) => $c['content'] ?? '', $ragContext));

        $prompt = <<<PROMPT
คุณเป็นผู้ประเมินคุณภาพ AI Chatbot ให้ประเมินว่าคำตอบของ Bot อ้างอิงจากข้อมูลที่มีหรือไม่ (ไม่ hallucinate)

## บทสนทนา
{$conversationText}

## ข้อมูลจาก Knowledge Base ที่ถูกดึงมา
{$contextText}

## เกณฑ์การประเมิน Faithfulness
1.0 = คำตอบทั้งหมดอ้างอิงจากข้อมูลที่มี หรือเป็นความรู้ทั่วไป
0.8 = คำตอบส่วนใหญ่ถูกต้อง มีการตีความเล็กน้อย
0.6 = มีข้อมูลบางส่วนที่ไม่แน่ใจว่ามาจากไหน
0.4 = มีการเพิ่มข้อมูลที่ไม่มีในแหล่งอ้างอิง
0.2 = หลายส่วนเป็นข้อมูลที่แต่งขึ้นมา
0.0 = คำตอบเป็น hallucination ทั้งหมด

## รูปแบบ Output (JSON)
{
  "score": 0.0-1.0,
  "reasoning": "เหตุผลสั้นๆ ภาษาไทย",
  "hallucinations": ["รายการข้อมูลที่อาจเป็น hallucination (ถ้ามี)"]
}
PROMPT;

        return $this->callJudge($prompt, 'faithfulness', $apiKey);
    }

    /**
     * Evaluate Role Adherence: Does the bot follow its system prompt persona?
     */
    protected function evaluateRoleAdherence(array $conversation, string $systemPrompt, ?string $apiKey): array
    {
        $conversationText = $this->formatConversation($conversation);
        $systemPromptText = $systemPrompt ?: 'ไม่มี system prompt กำหนด (ใช้ default)';

        $prompt = <<<PROMPT
คุณเป็นผู้ประเมินคุณภาพ AI Chatbot ให้ประเมินว่า Bot ทำตาม persona/บทบาท ที่กำหนดไว้หรือไม่

## System Prompt ของ Bot
{$systemPromptText}

## บทสนทนา
{$conversationText}

## เกณฑ์การประเมิน Role Adherence
1.0 = ทำตาม persona ทุกประการ ภาษา น้ำเสียง ขอบเขต ถูกต้อง
0.8 = ทำตาม persona ส่วนใหญ่ มีบางจุดที่หลุด
0.6 = ทำตาม persona ได้บ้าง แต่ไม่สม่ำเสมอ
0.4 = มีปัญหาด้าน persona ชัดเจน
0.2 = ไม่ค่อยทำตาม persona
0.0 = ไม่ทำตาม persona เลย หลุดบทบาทอย่างชัดเจน

## รูปแบบ Output (JSON)
{
  "score": 0.0-1.0,
  "reasoning": "เหตุผลสั้นๆ ภาษาไทย"
}
PROMPT;

        return $this->callJudge($prompt, 'role_adherence', $apiKey);
    }

    /**
     * Evaluate Context Precision: Were the most relevant KB chunks retrieved?
     */
    protected function evaluateContextPrecision(EvaluationTestCase $testCase, array $ragContext, ?string $apiKey): array
    {
        $expectedTopics = $testCase->expected_topics ?? [];
        $expectedText = empty($expectedTopics) ? 'ไม่ระบุ' : implode(', ', $expectedTopics);

        $contextText = empty($ragContext)
            ? 'ไม่มีข้อมูลจาก Knowledge Base'
            : implode("\n---\n", array_map(fn ($c) => $c['content'] ?? '', $ragContext));

        $userQuestion = $testCase->messages()
            ->where('role', 'user')
            ->first()
            ?->content ?? 'Unknown';

        $prompt = <<<PROMPT
คุณเป็นผู้ประเมินคุณภาพระบบ RAG ให้ประเมินว่าข้อมูลที่ดึงมาตรงกับคำถามหรือไม่

## คำถามของลูกค้า
{$userQuestion}

## หัวข้อที่คาดว่าจะเกี่ยวข้อง
{$expectedText}

## ข้อมูลที่ถูกดึงมาจาก Knowledge Base
{$contextText}

## เกณฑ์การประเมิน Context Precision
1.0 = ข้อมูลที่ดึงมาตรงประเด็นทั้งหมด ไม่มี noise
0.8 = ข้อมูลส่วนใหญ่ตรงประเด็น มีบ้างที่ไม่เกี่ยว
0.6 = ข้อมูลเกี่ยวข้องปานกลาง มี noise พอสมควร
0.4 = ข้อมูลเกี่ยวข้องบางส่วน มี noise มาก
0.2 = ข้อมูลแทบไม่เกี่ยวข้อง
0.0 = ข้อมูลไม่เกี่ยวข้องเลย หรือไม่มีข้อมูล

## รูปแบบ Output (JSON)
{
  "score": 0.0-1.0,
  "reasoning": "เหตุผลสั้นๆ ภาษาไทย"
}
PROMPT;

        return $this->callJudge($prompt, 'context_precision', $apiKey);
    }

    /**
     * Evaluate Task Completion: Was the user's goal achieved?
     */
    protected function evaluateTaskCompletion(array $conversation, EvaluationTestCase $testCase, ?string $apiKey): array
    {
        $conversationText = $this->formatConversation($conversation);
        $testType = $testCase->test_type;

        $prompt = <<<PROMPT
คุณเป็นผู้ประเมินคุณภาพ AI Chatbot ให้ประเมินว่า Bot ช่วยให้ลูกค้าบรรลุเป้าหมายหรือไม่

## ประเภทการทดสอบ
{$testType}

## บทสนทนา
{$conversationText}

## เกณฑ์การประเมิน Task Completion
1.0 = ลูกค้าได้รับข้อมูล/ความช่วยเหลือครบถ้วน น่าพอใจ
0.8 = ลูกค้าได้รับความช่วยเหลือส่วนใหญ่ อาจต้องติดตามเล็กน้อย
0.6 = ลูกค้าได้รับความช่วยเหลือบางส่วน ยังไม่สมบูรณ์
0.4 = ลูกค้าได้รับข้อมูลน้อย ต้องหาจากที่อื่น
0.2 = ลูกค้าแทบไม่ได้รับความช่วยเหลือ
0.0 = ไม่ได้ช่วยเหลือลูกค้าเลย หรือทำให้แย่ลง

## รูปแบบ Output (JSON)
{
  "score": 0.0-1.0,
  "reasoning": "เหตุผลสั้นๆ ภาษาไทย"
}
PROMPT;

        return $this->callJudge($prompt, 'task_completion', $apiKey);
    }

    /**
     * Call judge model with tier selection and fallback
     */
    protected function callJudge(string $prompt, string $metricName, ?string $apiKey): array
    {
        // Get model tier configuration for this metric
        $config = $this->tierSelector->selectForMetric($metricName);

        Log::info('Evaluating metric with tier system', [
            'metric' => $metricName,
            'tier' => $config->tier,
            'primary_model' => $config->modelId,
            'fallback_model' => $config->fallbackModelId,
        ]);

        // Try primary model first
        $modelUsed = $config->modelId;
        $fallbackOccurred = false;

        try {
            $response = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $config->modelId,
                temperature: 0.1, // Low temperature for consistent evaluation
                maxTokens: 500,
                apiKeyOverride: $apiKey
            );
        } catch (\Exception $e) {
            // Primary model failed, try fallback if available
            if ($config->fallbackModelId) {
                Log::warning('Primary model failed, using fallback', [
                    'metric' => $metricName,
                    'primary_model' => $config->modelId,
                    'fallback_model' => $config->fallbackModelId,
                    'error' => $e->getMessage(),
                ]);

                $fallbackOccurred = true;
                $modelUsed = $config->fallbackModelId;

                try {
                    $response = $this->openRouter->chat(
                        messages: [['role' => 'user', 'content' => $prompt]],
                        model: $config->fallbackModelId,
                        temperature: 0.1,
                        maxTokens: 500,
                        apiKeyOverride: $apiKey
                    );
                } catch (\Exception $fallbackError) {
                    Log::error('Both primary and fallback models failed', [
                        'metric' => $metricName,
                        'primary_error' => $e->getMessage(),
                        'fallback_error' => $fallbackError->getMessage(),
                    ]);
                    throw $fallbackError;
                }
            } else {
                // No fallback available, rethrow error
                Log::error('Primary model failed with no fallback available', [
                    'metric' => $metricName,
                    'model' => $config->modelId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        $tokensUsed = ($response['usage']['prompt_tokens'] ?? 0) +
                      ($response['usage']['completion_tokens'] ?? 0);

        $result = $this->parseJudgeResponse($response['content']);
        $result['tokens_used'] = $tokensUsed;

        // Add model metadata for cost tracking
        $result['model_metadata'] = [
            'tier' => $config->tier,
            'model_used' => $modelUsed,
            'fallback_occurred' => $fallbackOccurred,
        ];

        Log::info('Metric evaluation completed', [
            'metric' => $metricName,
            'tier' => $config->tier,
            'model_used' => $modelUsed,
            'fallback_occurred' => $fallbackOccurred,
            'score' => $result['score'],
            'tokens_used' => $tokensUsed,
        ]);

        return $result;
    }

    /**
     * Parse judge response
     */
    protected function parseJudgeResponse(string $content): array
    {
        // Try to extract JSON
        if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json && isset($json['score'])) {
                return [
                    'score' => max(0, min(1, (float) $json['score'])),
                    'reasoning' => $json['reasoning'] ?? '',
                    'hallucinations' => $json['hallucinations'] ?? [],
                ];
            }
        }

        // Fallback: try to extract score from text
        if (preg_match('/score[:\s]+([0-9.]+)/i', $content, $matches)) {
            return [
                'score' => max(0, min(1, (float) $matches[1])),
                'reasoning' => $content,
            ];
        }

        return [
            'score' => 0.5, // Default middle score
            'reasoning' => "Could not parse response: {$content}",
        ];
    }

    /**
     * Format conversation for prompt
     */
    protected function formatConversation(array $conversation): string
    {
        return collect($conversation)->map(function ($msg) {
            $role = $msg['role'] === 'user' ? 'ลูกค้า' : 'Bot';

            return "{$role}: {$msg['content']}";
        })->implode("\n\n");
    }

    /**
     * Batch evaluate multiple test cases
     */
    public function batchEvaluate(
        array $testCases,
        Flow $flow,
        ?string $apiKey = null
    ): array {
        $results = [];
        $totalTokens = 0;

        foreach ($testCases as $testCase) {
            $result = $this->evaluateTestCase($testCase, $flow, $apiKey);
            $results[$testCase->id] = $result;
            $totalTokens += $result['tokens_used'];
        }

        return [
            'results' => $results,
            'total_tokens' => $totalTokens,
        ];
    }
}
