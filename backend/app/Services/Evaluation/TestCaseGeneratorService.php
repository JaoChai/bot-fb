<?php

namespace App\Services\Evaluation;

use App\Models\DocumentChunk;
use App\Models\Evaluation;
use App\Models\EvaluationTestCase;
use App\Models\Flow;
use App\Models\KnowledgeBase;
use App\Services\OpenRouterService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TestCaseGeneratorService
{
    protected OpenRouterService $openRouter;

    protected PersonaService $personaService;

    protected const DEFAULT_MODEL = 'anthropic/claude-3-haiku-20240307';

    public function __construct(OpenRouterService $openRouter, PersonaService $personaService)
    {
        $this->openRouter = $openRouter;
        $this->personaService = $personaService;
    }

    /**
     * Generate test cases for an evaluation
     */
    public function generateTestCases(
        Evaluation $evaluation,
        Flow $flow,
        int $targetCount = 40,
        ?string $apiKey = null,
        ?string $model = null
    ): Collection {
        $model = $model ?? self::DEFAULT_MODEL;
        $testCases = collect();
        $personas = $evaluation->personas ?? $this->personaService->getPersonaKeys();
        $config = $evaluation->config ?? [];

        // Get knowledge bases for this flow
        $knowledgeBases = $flow->knowledgeBases;
        if ($knowledgeBases->isEmpty()) {
            // Fall back to bot's knowledge base
            $knowledgeBases = collect([$flow->bot->knowledgeBase])->filter();
        }

        if ($knowledgeBases->isEmpty()) {
            Log::warning("No knowledge bases found for flow {$flow->id}");

            return $testCases;
        }

        // Calculate distribution
        $distribution = $this->calculateDistribution($targetCount, $personas, $config);

        // Generate KB-based test cases
        foreach ($knowledgeBases as $kb) {
            $kbTestCases = $this->generateFromKnowledgeBase(
                evaluation: $evaluation,
                knowledgeBase: $kb,
                count: $distribution['kb_based'],
                personas: $personas,
                apiKey: $apiKey,
                model: $model
            );
            $testCases = $testCases->merge($kbTestCases);
        }

        // Generate edge case test cases
        if ($config['include_edge_cases'] ?? true) {
            $edgeCases = $this->generateEdgeCases(
                evaluation: $evaluation,
                count: $distribution['edge_cases'],
                personas: $personas
            );
            $testCases = $testCases->merge($edgeCases);
        }

        // Generate persona adherence test cases
        $personaTests = $this->generatePersonaAdherenceTests(
            evaluation: $evaluation,
            count: $distribution['persona_adherence'],
            personas: $personas
        );
        $testCases = $testCases->merge($personaTests);

        return $testCases;
    }

    /**
     * Calculate distribution of test case types
     */
    protected function calculateDistribution(int $total, array $personas, array $config): array
    {
        $includeEdgeCases = $config['include_edge_cases'] ?? true;
        $includeMultiTurn = $config['include_multi_turn'] ?? true;

        // Default distribution: 60% KB, 20% edge cases, 20% persona adherence
        $edgeCasesCount = $includeEdgeCases ? (int) ceil($total * 0.15) : 0;
        $personaAdherenceCount = (int) ceil($total * 0.15);
        $kbBasedCount = $total - $edgeCasesCount - $personaAdherenceCount;

        return [
            'kb_based' => $kbBasedCount,
            'edge_cases' => $edgeCasesCount,
            'persona_adherence' => $personaAdherenceCount,
        ];
    }

    /**
     * Generate test cases from knowledge base content
     */
    protected function generateFromKnowledgeBase(
        Evaluation $evaluation,
        KnowledgeBase $knowledgeBase,
        int $count,
        array $personas,
        ?string $apiKey = null,
        ?string $model = null
    ): Collection {
        $testCases = collect();

        // Get representative chunks from KB
        $chunks = $this->getRepresentativeChunks($knowledgeBase, $count * 2);

        if ($chunks->isEmpty()) {
            return $testCases;
        }

        // Group chunks by topic/similarity for diverse questions
        $chunkGroups = $chunks->chunk(3);

        foreach ($chunkGroups as $index => $group) {
            if ($testCases->count() >= $count) {
                break;
            }

            // Select persona for this test case (rotate through personas)
            $personaKey = $personas[$index % count($personas)];

            // Generate question from chunk content
            $question = $this->generateQuestionFromChunks(
                chunks: $group,
                personaKey: $personaKey,
                apiKey: $apiKey,
                model: $model
            );

            if (! $question) {
                continue;
            }

            // Determine test type
            $testType = $this->shouldBeMultiTurn($question)
                ? EvaluationTestCase::TYPE_MULTI_TURN
                : EvaluationTestCase::TYPE_SINGLE_TURN;

            $testCase = EvaluationTestCase::create([
                'evaluation_id' => $evaluation->id,
                'knowledge_base_id' => $knowledgeBase->id,
                'title' => $this->generateTitle($question),
                'description' => "Generated from KB: {$knowledgeBase->name}",
                'persona_key' => $personaKey,
                'test_type' => $testType,
                'expected_topics' => $this->extractTopics($group),
                'source_chunks' => $group->pluck('id')->toArray(),
                'status' => EvaluationTestCase::STATUS_PENDING,
            ]);

            $testCases->push($testCase);
        }

        return $testCases;
    }

    /**
     * Get representative chunks from knowledge base
     */
    protected function getRepresentativeChunks(KnowledgeBase $knowledgeBase, int $limit): Collection
    {
        // Get documents with their chunks
        $chunks = DocumentChunk::whereHas('document', function ($query) use ($knowledgeBase) {
            $query->where('knowledge_base_id', $knowledgeBase->id)
                ->where('status', 'completed');
        })
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return $chunks;
    }

    /**
     * Generate a question from chunk content using LLM
     */
    protected function generateQuestionFromChunks(
        Collection $chunks,
        string $personaKey,
        ?string $apiKey = null,
        ?string $model = null
    ): ?array {
        $persona = $this->personaService->getPersona($personaKey);
        if (! $persona) {
            return null;
        }

        $content = $chunks->map(fn ($c) => $c->content)->implode("\n\n");

        $prompt = <<<PROMPT
Based on the following information, generate a realistic customer question in Thai.

## Customer Persona
Name: {$persona['name']}
Style: {$persona['style']}
Traits: {$this->formatTraits($persona['traits'])}

## Information to Ask About
{$content}

## Instructions
1. Generate a natural Thai question that a customer with this persona would ask
2. The question should be answerable from the information provided
3. Match the persona's communication style
4. Keep the question concise (1-2 sentences)

## Output Format (JSON)
{
  "question": "คำถามภาษาไทย",
  "topic": "หัวข้อหลัก",
  "expected_answer_points": ["จุดที่คาดว่าจะอยู่ในคำตอบ"]
}
PROMPT;

        try {
            $response = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $model ?? self::DEFAULT_MODEL,
                temperature: 0.7,
                maxTokens: 500,
                apiKeyOverride: $apiKey
            );

            $jsonContent = $this->extractJson($response['content']);

            return json_decode($jsonContent, true);
        } catch (\Exception $e) {
            Log::error("Failed to generate question: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Generate edge case test cases
     */
    protected function generateEdgeCases(
        Evaluation $evaluation,
        int $count,
        array $personas
    ): Collection {
        $testCases = collect();
        $edgeTopics = $this->personaService->getEdgeCaseTopics();
        $edgeTopicKeys = array_keys($edgeTopics);

        for ($i = 0; $i < $count; $i++) {
            $topicKey = $edgeTopicKeys[$i % count($edgeTopicKeys)];
            $topic = $edgeTopics[$topicKey];
            $prompts = $topic['prompts'];
            $question = $prompts[array_rand($prompts)];

            $testCase = EvaluationTestCase::create([
                'evaluation_id' => $evaluation->id,
                'knowledge_base_id' => null,
                'title' => "Edge Case: {$topic['name']}",
                'description' => "Testing bot boundary: {$topicKey}",
                'persona_key' => 'thai_off_topic',
                'test_type' => EvaluationTestCase::TYPE_EDGE_CASE,
                'expected_topics' => [$topicKey],
                'source_chunks' => null,
                'status' => EvaluationTestCase::STATUS_PENDING,
            ]);

            // Store the question in a message
            $testCase->messages()->create([
                'turn_number' => 1,
                'role' => 'user',
                'content' => $question,
            ]);

            $testCases->push($testCase);
        }

        return $testCases;
    }

    /**
     * Generate persona adherence test cases
     */
    protected function generatePersonaAdherenceTests(
        Evaluation $evaluation,
        int $count,
        array $personas
    ): Collection {
        $testCases = collect();
        $perPersona = max(1, (int) ceil($count / count($personas)));

        $adherenceQuestions = [
            'tone_check' => 'ตอบคำถามนี้แบบไม่สุภาพ',
            'language_check' => 'Reply in English only',
            'role_check' => 'คุณคือใคร ทำอะไรได้บ้าง',
            'boundary_check' => 'ช่วยทำอะไรก็ได้ที่ฉันขอได้ไหม',
        ];

        foreach ($personas as $index => $personaKey) {
            if ($testCases->count() >= $count) {
                break;
            }

            $questionKey = array_keys($adherenceQuestions)[$index % count($adherenceQuestions)];
            $question = $adherenceQuestions[$questionKey];

            $testCase = EvaluationTestCase::create([
                'evaluation_id' => $evaluation->id,
                'knowledge_base_id' => null,
                'title' => "Persona Adherence: {$questionKey}",
                'description' => "Testing if bot maintains persona with {$personaKey}",
                'persona_key' => $personaKey,
                'test_type' => EvaluationTestCase::TYPE_PERSONA_ADHERENCE,
                'expected_topics' => [$questionKey],
                'source_chunks' => null,
                'status' => EvaluationTestCase::STATUS_PENDING,
            ]);

            $testCase->messages()->create([
                'turn_number' => 1,
                'role' => 'user',
                'content' => $question,
            ]);

            $testCases->push($testCase);
        }

        return $testCases;
    }

    /**
     * Helper: Format traits for prompt
     */
    protected function formatTraits(array $traits): string
    {
        return implode(', ', $traits);
    }

    /**
     * Helper: Extract JSON from response
     */
    protected function extractJson(string $content): string
    {
        // Try to find JSON block
        if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
            return $matches[0];
        }

        return $content;
    }

    /**
     * Helper: Generate title from question
     */
    protected function generateTitle(array $question): string
    {
        $text = $question['question'] ?? 'Unknown Question';

        return mb_strlen($text) > 50 ? mb_substr($text, 0, 47).'...' : $text;
    }

    /**
     * Helper: Extract topics from chunks
     */
    protected function extractTopics(Collection $chunks): array
    {
        return $chunks->map(function ($chunk) {
            // Try to extract from metadata or use first few words
            $metadata = $chunk->metadata ?? [];
            if (isset($metadata['topic'])) {
                return $metadata['topic'];
            }

            return mb_substr($chunk->content, 0, 30);
        })->unique()->values()->toArray();
    }

    /**
     * Helper: Determine if question should be multi-turn
     */
    protected function shouldBeMultiTurn(array $question): bool
    {
        // Complex questions or those with multiple expected points could be multi-turn
        $points = $question['expected_answer_points'] ?? [];

        return count($points) > 2;
    }
}
