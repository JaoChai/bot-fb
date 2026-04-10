<?php

namespace Tests\Unit\Services\Agent;

use App\Services\Agent\AgentLoopService;
use App\Services\AgentSafetyService;
use App\Services\CostTrackingService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\ToolService;
use Tests\TestCase;

class SmartRoutingTest extends TestCase
{
    private AgentLoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AgentLoopService(
            $this->createMock(OpenRouterService::class),
            $this->createMock(ToolService::class),
            $this->createMock(AgentSafetyService::class),
            $this->createMock(CostTrackingService::class),
            $this->createMock(MultipleBubblesService::class),
            app(\App\Services\StockInjectionService::class),
        );
    }

    public function test_simple_with_quality_kb_skips_agent(): void
    {
        $result = $this->service->shouldUseAgentLoop(
            complexity: ['is_complex' => false, 'score' => 0],
            toolIntent: ['needs_tool' => false, 'tool_hint' => null],
            kbTopRelevance: 0.85,
            threshold: 0.7,
        );

        $this->assertFalse($result['use_agent']);
        $this->assertEquals('simple_with_quality_kb', $result['reason']);
    }

    public function test_complex_question_uses_agent(): void
    {
        $result = $this->service->shouldUseAgentLoop(
            complexity: ['is_complex' => true, 'score' => 3],
            toolIntent: ['needs_tool' => false, 'tool_hint' => null],
            kbTopRelevance: 0.85,
            threshold: 0.7,
        );

        $this->assertTrue($result['use_agent']);
        $this->assertEquals('complex_question', $result['reason']);
    }

    public function test_tool_intent_uses_agent(): void
    {
        $result = $this->service->shouldUseAgentLoop(
            complexity: ['is_complex' => false, 'score' => 0],
            toolIntent: ['needs_tool' => true, 'tool_hint' => 'calculate'],
            kbTopRelevance: 0.85,
            threshold: 0.7,
        );

        $this->assertTrue($result['use_agent']);
        $this->assertEquals('tool_intent:calculate', $result['reason']);
    }

    public function test_low_kb_quality_uses_agent(): void
    {
        $result = $this->service->shouldUseAgentLoop(
            complexity: ['is_complex' => false, 'score' => 0],
            toolIntent: ['needs_tool' => false, 'tool_hint' => null],
            kbTopRelevance: 0.5,
            threshold: 0.7,
        );

        $this->assertTrue($result['use_agent']);
        $this->assertEquals('low_quality_kb', $result['reason']);
    }

    public function test_no_kb_results_uses_agent(): void
    {
        $result = $this->service->shouldUseAgentLoop(
            complexity: ['is_complex' => false, 'score' => 0],
            toolIntent: ['needs_tool' => false, 'tool_hint' => null],
            kbTopRelevance: 0.0,
            threshold: 0.7,
        );

        $this->assertTrue($result['use_agent']);
        $this->assertEquals('no_kb_results', $result['reason']);
    }
}
