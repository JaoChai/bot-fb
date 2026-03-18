<?php

namespace Tests\Unit\Services;

use App\Services\CRAGService;
use App\Services\OpenRouterService;
use Tests\TestCase;

class CRAGServiceTest extends TestCase
{
    private CRAGService $service;

    private OpenRouterService $openRouter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openRouter = $this->createMock(OpenRouterService::class);

        // Enable CRAG for testing
        config(['rag.crag.enabled' => true]);
        config(['rag.crag.correct_threshold' => 0.7]);
        config(['rag.crag.ambiguous_threshold' => 0.3]);
        config(['rag.crag.max_rewrite_attempts' => 2]);

        $this->service = new CRAGService($this->openRouter);
    }

    public function test_is_enabled_returns_config_value(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    public function test_evaluate_returns_correct_for_high_similarity(): void
    {
        $results = collect([
            ['id' => 1, 'content' => 'test', 'similarity' => 0.85],
            ['id' => 2, 'content' => 'test2', 'similarity' => 0.72],
        ]);

        $evaluation = $this->service->evaluate($results, 'test query');

        $this->assertEquals(CRAGService::GRADE_CORRECT, $evaluation['grade']);
        $this->assertEquals(0.85, $evaluation['top_similarity']);
        $this->assertEquals('high_similarity', $evaluation['reason']);
    }

    public function test_evaluate_returns_ambiguous_for_mid_similarity(): void
    {
        $results = collect([
            ['id' => 1, 'content' => 'test', 'similarity' => 0.55],
            ['id' => 2, 'content' => 'test2', 'similarity' => 0.45],
        ]);

        $evaluation = $this->service->evaluate($results, 'test query');

        $this->assertEquals(CRAGService::GRADE_AMBIGUOUS, $evaluation['grade']);
        $this->assertEquals(0.55, $evaluation['top_similarity']);
        $this->assertEquals('mid_similarity', $evaluation['reason']);
    }

    public function test_evaluate_returns_incorrect_for_low_similarity(): void
    {
        $results = collect([
            ['id' => 1, 'content' => 'test', 'similarity' => 0.2],
            ['id' => 2, 'content' => 'test2', 'similarity' => 0.15],
        ]);

        $evaluation = $this->service->evaluate($results, 'test query');

        $this->assertEquals(CRAGService::GRADE_INCORRECT, $evaluation['grade']);
        $this->assertEquals(0.2, $evaluation['top_similarity']);
        $this->assertEquals('low_similarity', $evaluation['reason']);
    }

    public function test_evaluate_returns_incorrect_for_empty_results(): void
    {
        $results = collect([]);

        $evaluation = $this->service->evaluate($results);

        $this->assertEquals(CRAGService::GRADE_INCORRECT, $evaluation['grade']);
        $this->assertEquals(0.0, $evaluation['top_similarity']);
        $this->assertEquals('no_results', $evaluation['reason']);
    }

    public function test_evaluate_at_exact_correct_threshold(): void
    {
        $results = collect([
            ['id' => 1, 'content' => 'test', 'similarity' => 0.7],
        ]);

        $evaluation = $this->service->evaluate($results, 'test query');

        $this->assertEquals(CRAGService::GRADE_CORRECT, $evaluation['grade']);
    }

    public function test_evaluate_at_exact_ambiguous_threshold(): void
    {
        $results = collect([
            ['id' => 1, 'content' => 'test', 'similarity' => 0.3],
        ]);

        $evaluation = $this->service->evaluate($results, 'test query');

        $this->assertEquals(CRAGService::GRADE_AMBIGUOUS, $evaluation['grade']);
    }

    public function test_evaluate_just_below_ambiguous_threshold(): void
    {
        $results = collect([
            ['id' => 1, 'content' => 'test', 'similarity' => 0.29],
        ]);

        $evaluation = $this->service->evaluate($results, 'test query');

        $this->assertEquals(CRAGService::GRADE_INCORRECT, $evaluation['grade']);
    }

    public function test_rewrite_query_calls_llm_and_returns_result(): void
    {
        $this->openRouter->method('chat')
            ->willReturn(['content' => 'rewritten search query']);

        $failedResults = collect([
            ['id' => 1, 'content' => 'some content', 'similarity' => 0.5],
        ]);

        $result = $this->service->rewriteQuery('original query', $failedResults);

        $this->assertEquals('rewritten search query', $result);
    }

    public function test_rewrite_query_returns_original_on_failure(): void
    {
        $this->openRouter->method('chat')
            ->willThrowException(new \RuntimeException('API error'));

        $failedResults = collect([
            ['id' => 1, 'content' => 'some content', 'similarity' => 0.5],
        ]);

        $result = $this->service->rewriteQuery('original query', $failedResults);

        $this->assertEquals('original query', $result);
    }

    public function test_rewrite_query_returns_original_when_llm_returns_same(): void
    {
        $this->openRouter->method('chat')
            ->willReturn(['content' => 'original query']);

        $failedResults = collect([
            ['id' => 1, 'content' => 'some content', 'similarity' => 0.5],
        ]);

        $result = $this->service->rewriteQuery('original query', $failedResults);

        $this->assertEquals('original query', $result);
    }

    public function test_get_max_rewrite_attempts(): void
    {
        $this->assertEquals(2, $this->service->getMaxRewriteAttempts());
    }

    public function test_get_incorrect_action(): void
    {
        $this->assertEquals('skip_kb', $this->service->getIncorrectAction());
    }
}
