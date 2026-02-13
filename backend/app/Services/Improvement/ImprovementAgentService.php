<?php

namespace App\Services\Improvement;

use App\Models\Bot;
use App\Models\Evaluation;
use App\Models\EvaluationReport;
use App\Models\EvaluationTestCase;
use App\Models\Flow;
use App\Models\ImprovementSession;
use App\Models\ImprovementSuggestion;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\Evaluation\EvaluationService;
use App\Services\FlowCacheService;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImprovementAgentService
{
    protected string $defaultAgentModel = 'anthropic/claude-3.5-sonnet';

    public function __construct(
        protected OpenRouterService $openRouter,
        protected FlowCacheService $flowCache,
        protected EvaluationService $evaluationService
    ) {}

    /**
     * Start a new improvement session from an evaluation
     */
    public function startSession(
        Evaluation $evaluation,
        User $user,
        ?string $agentModel = null
    ): ImprovementSession {
        $flow = $evaluation->flow;
        $bot = $evaluation->bot;

        // Snapshot current state for potential rollback
        $kbSnapshot = $this->createKbSnapshot($flow);

        $session = ImprovementSession::create([
            'evaluation_id' => $evaluation->id,
            'flow_id' => $flow->id,
            'bot_id' => $bot->id,
            'user_id' => $user->id,
            'status' => ImprovementSession::STATUS_ANALYZING,
            'original_system_prompt' => $flow->system_prompt,
            'original_kb_snapshot' => $kbSnapshot,
            'before_score' => $evaluation->overall_score,
            'agent_model' => $agentModel ?? $this->defaultAgentModel,
            'started_at' => now(),
        ]);

        return $session;
    }

    /**
     * Create a snapshot of KB state
     */
    protected function createKbSnapshot(Flow $flow): array
    {
        return $flow->knowledgeBases->map(function ($kb) {
            return [
                'id' => $kb->id,
                'name' => $kb->name,
                'document_count' => $kb->document_count,
                'chunk_count' => $kb->chunk_count,
            ];
        })->toArray();
    }

    /**
     * Analyze evaluation and generate suggestions
     */
    public function analyzeEvaluation(
        ImprovementSession $session,
        ?string $apiKey = null
    ): void {
        try {
            $evaluation = $session->evaluation;
            $flow = $session->flow;
            $report = $evaluation->report;

            if (!$report) {
                throw new \Exception('Evaluation report not found');
            }

            // Generate analysis summary
            $analysis = $this->generateAnalysis($report, $flow, $session->agent_model, $apiKey);
            $session->addTokensUsed($analysis['tokens_used']);

            // Generate suggestions
            $this->generateSuggestions($session, $report, $flow, $apiKey);

            // Mark as suggestions ready
            $session->markAsSuggestionsReady($analysis['summary']);
        } catch (\Exception $e) {
            Log::error("Improvement analysis failed: {$e->getMessage()}", [
                'session_id' => $session->id,
                ...(!app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);
            $session->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate analysis summary
     */
    protected function generateAnalysis(
        EvaluationReport $report,
        Flow $flow,
        string $model,
        ?string $apiKey
    ): array {
        $weaknesses = json_encode($report->weaknesses ?? [], JSON_UNESCAPED_UNICODE);
        $kbGaps = json_encode($report->kb_gaps ?? [], JSON_UNESCAPED_UNICODE);
        $recommendations = json_encode($report->recommendations ?? [], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
คุณเป็น AI Agent ที่ช่วยวิเคราะห์ผลการประเมิน Chatbot และสรุปปัญหาที่พบ

## ข้อมูลจาก Evaluation Report

### จุดอ่อนที่พบ (Weaknesses)
{$weaknesses}

### ช่องว่างใน Knowledge Base
{$kbGaps}

### คำแนะนำจากระบบ
{$recommendations}

## งานของคุณ

สรุปการวิเคราะห์เป็นภาษาไทย ความยาว 2-3 ประโยค โดย:
1. ระบุปัญหาหลักที่พบ
2. ระบุแนวทางแก้ไขเบื้องต้น

ตอบเป็นข้อความเดียว ไม่ต้องมี JSON หรือ format พิเศษ
PROMPT;

        $response = $this->openRouter->chat(
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $model,
            temperature: 0.3,
            maxTokens: 500,
            apiKeyOverride: $apiKey
        );

        $tokensUsed = ($response['usage']['prompt_tokens'] ?? 0) +
                      ($response['usage']['completion_tokens'] ?? 0);

        return [
            'summary' => $response['content'],
            'tokens_used' => $tokensUsed,
        ];
    }

    /**
     * Generate all suggestions
     */
    protected function generateSuggestions(
        ImprovementSession $session,
        EvaluationReport $report,
        Flow $flow,
        ?string $apiKey
    ): void {
        // Generate prompt suggestions
        $promptSuggestions = $this->generatePromptSuggestions(
            $session, $report, $flow, $apiKey
        );

        // Generate KB suggestions
        $kbSuggestions = $this->generateKbSuggestions(
            $session, $report, $flow, $apiKey
        );

        Log::info("Generated suggestions", [
            'session_id' => $session->id,
            'prompt_count' => count($promptSuggestions),
            'kb_count' => count($kbSuggestions),
        ]);
    }

    /**
     * Generate system prompt improvement suggestions
     */
    protected function generatePromptSuggestions(
        ImprovementSession $session,
        EvaluationReport $report,
        Flow $flow,
        ?string $apiKey
    ): array {
        $currentPrompt = $flow->system_prompt ?? '';
        $weaknesses = $report->weaknesses ?? [];
        $promptSuggestions = $report->prompt_suggestions ?? [];

        // Filter weaknesses related to prompt (role_adherence, answer_relevancy)
        $promptRelatedWeaknesses = array_filter($weaknesses, function ($w) {
            $metric = $w['metric'] ?? '';
            return in_array($metric, ['role_adherence', 'answer_relevancy', 'faithfulness']);
        });

        if (empty($promptRelatedWeaknesses) && empty($promptSuggestions)) {
            return [];
        }

        $weaknessesJson = json_encode($promptRelatedWeaknesses, JSON_UNESCAPED_UNICODE);
        $suggestionsJson = json_encode($promptSuggestions, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
คุณเป็น AI Agent ที่ช่วยปรับปรุง System Prompt ของ Chatbot

## System Prompt ปัจจุบัน
```
{$currentPrompt}
```

## จุดอ่อนที่เกี่ยวข้อง
{$weaknessesJson}

## คำแนะนำจากระบบ
{$suggestionsJson}

## งานของคุณ

สร้าง System Prompt ใหม่ที่ปรับปรุงจากเดิม โดย:
1. รักษาโครงสร้างและเนื้อหาหลักของ prompt เดิม
2. เพิ่มคำสั่งที่แก้ไขจุดอ่อนที่พบ
3. เพิ่ม guardrails หากจำเป็น (เช่น ให้บอก "ไม่ทราบ" เมื่อไม่มีข้อมูล)

## รูปแบบ Output (JSON)
{
  "title": "ชื่อการปรับปรุง (ภาษาไทย สั้นๆ)",
  "description": "คำอธิบายสิ่งที่ปรับปรุง (ภาษาไทย)",
  "suggested_prompt": "System Prompt ใหม่ทั้งหมด",
  "diff_summary": "สรุปสิ่งที่เปลี่ยนแปลง (ภาษาไทย)",
  "confidence": 0.0-1.0,
  "priority": "high" หรือ "medium" หรือ "low",
  "source_metric": "metric หลักที่แก้ไข"
}
PROMPT;

        try {
            $response = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $session->agent_model,
                temperature: 0.2,
                maxTokens: 2000,
                apiKeyOverride: $apiKey
            );

            $tokensUsed = ($response['usage']['prompt_tokens'] ?? 0) +
                          ($response['usage']['completion_tokens'] ?? 0);
            $session->addTokensUsed($tokensUsed);

            $result = $this->parseJsonResponse($response['content']);

            if ($result && isset($result['suggested_prompt'])) {
                $suggestion = ImprovementSuggestion::create([
                    'session_id' => $session->id,
                    'type' => ImprovementSuggestion::TYPE_SYSTEM_PROMPT,
                    'priority' => $result['priority'] ?? 'medium',
                    'confidence_score' => $result['confidence'] ?? 0.8,
                    'title' => $result['title'] ?? 'ปรับปรุง System Prompt',
                    'description' => $result['description'] ?? null,
                    'current_value' => $currentPrompt,
                    'suggested_value' => $result['suggested_prompt'],
                    'diff_summary' => $result['diff_summary'] ?? null,
                    'source_metric' => $result['source_metric'] ?? null,
                    'is_selected' => true,
                ]);

                return [$suggestion];
            }
        } catch (\Exception $e) {
            Log::error("Failed to generate prompt suggestion: {$e->getMessage()}");
        }

        return [];
    }

    /**
     * Generate KB content suggestions
     */
    protected function generateKbSuggestions(
        ImprovementSession $session,
        EvaluationReport $report,
        Flow $flow,
        ?string $apiKey
    ): array {
        $kbGaps = $report->kb_gaps ?? [];
        $weaknesses = $report->weaknesses ?? [];

        // Filter weaknesses related to KB (context_precision, faithfulness)
        $kbRelatedWeaknesses = array_filter($weaknesses, function ($w) {
            $metric = $w['metric'] ?? '';
            return in_array($metric, ['context_precision', 'faithfulness']);
        });

        if (empty($kbGaps) && empty($kbRelatedWeaknesses)) {
            return [];
        }

        // Get primary KB
        $kb = $flow->knowledgeBases->first();
        if (!$kb) {
            Log::warning("No knowledge base found for flow {$flow->id}");
            return [];
        }

        $kbGapsJson = json_encode($kbGaps, JSON_UNESCAPED_UNICODE);
        $weaknessesJson = json_encode($kbRelatedWeaknesses, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
คุณเป็น AI Agent ที่ช่วยสร้างเนื้อหาสำหรับ Knowledge Base ของ Chatbot

## ช่องว่างใน Knowledge Base ที่พบ
{$kbGapsJson}

## จุดอ่อนที่เกี่ยวข้องกับ Knowledge Base
{$weaknessesJson}

## งานของคุณ

สร้างเอกสารใหม่สำหรับ Knowledge Base โดย:
1. เติมเต็มช่องว่างที่พบ
2. เขียนเนื้อหาที่ชัดเจน ครบถ้วน
3. ใช้ภาษาที่เหมาะสมกับ Chatbot ขายสินค้า/บริการ

## รูปแบบ Output (JSON Array)
[
  {
    "title": "หัวข้อเอกสาร (ภาษาไทย)",
    "description": "คำอธิบายสั้นๆ",
    "content": "เนื้อหาเอกสารแบบเต็ม (ใช้ markdown ได้)",
    "related_topics": ["หัวข้อที่เกี่ยวข้อง"],
    "confidence": 0.0-1.0,
    "priority": "high" หรือ "medium" หรือ "low",
    "source_metric": "metric ที่แก้ไข"
  }
]

สร้างเอกสารไม่เกิน 3 รายการ เลือกเฉพาะที่สำคัญที่สุด
PROMPT;

        try {
            $response = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $session->agent_model,
                temperature: 0.3,
                maxTokens: 3000,
                apiKeyOverride: $apiKey
            );

            $tokensUsed = ($response['usage']['prompt_tokens'] ?? 0) +
                          ($response['usage']['completion_tokens'] ?? 0);
            $session->addTokensUsed($tokensUsed);

            $results = $this->parseJsonResponse($response['content']);

            if (!is_array($results)) {
                $results = [$results];
            }

            $suggestions = [];
            foreach ($results as $result) {
                if (!isset($result['title']) || !isset($result['content'])) {
                    continue;
                }

                $suggestion = ImprovementSuggestion::create([
                    'session_id' => $session->id,
                    'type' => ImprovementSuggestion::TYPE_KB_CONTENT,
                    'priority' => $result['priority'] ?? 'medium',
                    'confidence_score' => $result['confidence'] ?? 0.7,
                    'title' => 'เพิ่มเนื้อหา: ' . $result['title'],
                    'description' => $result['description'] ?? null,
                    'target_knowledge_base_id' => $kb->id,
                    'kb_content_title' => $result['title'],
                    'kb_content_body' => $result['content'],
                    'related_topics' => $result['related_topics'] ?? [],
                    'source_metric' => $result['source_metric'] ?? 'context_precision',
                    'is_selected' => true,
                ]);

                $suggestions[] = $suggestion;
            }

            return $suggestions;
        } catch (\Exception $e) {
            Log::error("Failed to generate KB suggestions: {$e->getMessage()}");
        }

        return [];
    }

    /**
     * Apply selected suggestions
     */
    public function applySuggestions(
        ImprovementSession $session,
        ?string $apiKey = null
    ): void {
        try {
            $session->markAsApplying();

            $selectedSuggestions = $session->getSelectedSuggestions();

            foreach ($selectedSuggestions as $suggestion) {
                if ($suggestion->isSystemPrompt()) {
                    $this->applyPromptSuggestion($suggestion, $session->flow);
                } elseif ($suggestion->isKbContent()) {
                    $this->applyKbSuggestion($suggestion, $session->user);
                }

                $suggestion->markAsApplied();
            }

            // Trigger re-evaluation
            $this->triggerReEvaluation($session, $apiKey);
        } catch (\Exception $e) {
            Log::error("Failed to apply suggestions: {$e->getMessage()}", [
                'session_id' => $session->id,
            ]);
            $session->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Apply system prompt suggestion
     */
    protected function applyPromptSuggestion(
        ImprovementSuggestion $suggestion,
        Flow $flow
    ): void {
        $flow->update([
            'system_prompt' => $suggestion->suggested_value,
        ]);

        // Invalidate cache
        $this->flowCache->invalidateBot($flow->bot_id);

        Log::info("Applied prompt suggestion", [
            'suggestion_id' => $suggestion->id,
            'flow_id' => $flow->id,
        ]);
    }

    /**
     * Apply KB content suggestion (create document)
     */
    protected function applyKbSuggestion(
        ImprovementSuggestion $suggestion,
        User $user
    ): void {
        $kb = $suggestion->targetKnowledgeBase;
        if (!$kb) {
            throw new \Exception("Knowledge base not found for suggestion {$suggestion->id}");
        }

        // Create a text document from the content
        $filename = str_replace(' ', '_', $suggestion->kb_content_title) . '.txt';
        $content = "# {$suggestion->kb_content_title}\n\n{$suggestion->kb_content_body}";

        // Store as a document
        $document = $kb->documents()->create([
            'filename' => $filename,
            'original_filename' => $filename,
            'mime_type' => 'text/plain',
            'file_size' => strlen($content),
            'content' => $content,
            'status' => 'pending',
        ]);

        // Dispatch processing job
        \App\Jobs\ProcessDocument::dispatch($document, $user->id);

        Log::info("Applied KB suggestion", [
            'suggestion_id' => $suggestion->id,
            'document_id' => $document->id,
            'kb_id' => $kb->id,
        ]);
    }

    /**
     * Trigger re-evaluation after applying changes
     */
    public function triggerReEvaluation(
        ImprovementSession $session,
        ?string $apiKey = null
    ): void {
        $originalEval = $session->evaluation;

        // Create new evaluation with same config
        $newEvaluation = Evaluation::create([
            'bot_id' => $session->bot_id,
            'flow_id' => $session->flow_id,
            'user_id' => $session->user_id,
            'name' => "Re-evaluation: {$originalEval->name}",
            'description' => "Auto re-evaluation after improvement session #{$session->id}",
            'status' => Evaluation::STATUS_PENDING,
            'judge_model' => $originalEval->judge_model,
            'generator_model' => $originalEval->generator_model,
            'simulator_model' => $originalEval->simulator_model,
            'personas' => $originalEval->personas,
            'config' => $originalEval->config,
        ]);

        // Update session
        $session->markAsReEvaluating($newEvaluation->id);

        // Dispatch evaluation job
        \App\Jobs\Evaluation\RunEvaluationJob::dispatch($newEvaluation, $session->user_id);

        Log::info("Triggered re-evaluation", [
            'session_id' => $session->id,
            'new_evaluation_id' => $newEvaluation->id,
        ]);
    }

    /**
     * Complete session after re-evaluation
     */
    public function completeSession(ImprovementSession $session): void
    {
        $reEval = $session->reEvaluation;

        if (!$reEval || $reEval->status !== Evaluation::STATUS_COMPLETED) {
            return;
        }

        $session->markAsCompleted(
            $session->before_score ?? 0,
            $reEval->overall_score ?? 0
        );

        Log::info("Improvement session completed", [
            'session_id' => $session->id,
            'before_score' => $session->before_score,
            'after_score' => $reEval->overall_score,
            'improvement' => $session->score_improvement,
        ]);
    }

    /**
     * Cancel an improvement session
     */
    public function cancelSession(ImprovementSession $session): void
    {
        $session->markAsCancelled();
    }

    /**
     * Parse JSON response from LLM
     */
    protected function parseJsonResponse(string $content): ?array
    {
        // Try to extract JSON from response
        if (preg_match('/\[[\s\S]*\]/m', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        return null;
    }
}
