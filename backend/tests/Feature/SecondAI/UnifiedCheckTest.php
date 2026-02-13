<?php

namespace Tests\Feature\SecondAI;

use App\Models\Bot;
use App\Models\Flow;
use App\Services\OpenRouterService;
use App\Services\RAGService;
use App\Services\SecondAI\SecondAIService;
use App\Services\SecondAI\UnifiedCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedCheckTest extends TestCase
{
    use RefreshDatabase;

    protected Flow $flow;

    protected Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        // Create bot and flow with Second AI enabled
        $this->bot = Bot::factory()->create([
            'decision_model' => 'openai/gpt-4o-mini',
        ]);

        $this->flow = Flow::factory()->create([
            'bot_id' => $this->bot->id,
            'system_prompt' => 'You are a professional assistant.',
            'second_ai_enabled' => true,
            'second_ai_options' => [
                'fact_check' => true,
                'policy' => true,
                'personality' => false,
            ],
        ]);
    }

    public function test_unified_mode_is_used_when_multiple_checks_enabled(): void
    {
        $unifiedResponse = json_encode([
            'passed' => false,
            'modifications' => [
                'fact_check' => [
                    'required' => true,
                    'confidence' => 0.9,
                    'claims_extracted' => ['We have 1M users'],
                    'unverified_claims' => [],
                    'rewritten' => 'We have approximately 1 million users based on our records.',
                ],
                'policy' => [
                    'required' => false,
                    'confidence' => 1.0,
                    'violations' => [],
                    'rewritten' => null,
                ],
            ],
            'final_response' => 'We have approximately 1 million users based on our records.',
        ]);

        // Mock OpenRouterService to return valid unified response via chat()
        $this->mock(OpenRouterService::class, function ($mock) use ($unifiedResponse) {
            $mock->shouldReceive('chat')
                ->andReturn([
                    'content' => $unifiedResponse,
                    'model' => 'openai/gpt-4o-mini',
                    'usage' => [],
                ]);
        });

        // Mock RAGService
        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('getFlowKnowledgeBaseContext')->andReturn('');
        });

        $service = app(SecondAIService::class);

        $result = $service->process(
            response: 'We have approximately 1 million users on our platform currently.',
            flow: $this->flow,
            userMessage: 'How many users do you have?'
        );

        $this->assertEquals('We have approximately 1 million users based on our records.', $result['content']);
        $this->assertTrue($result['second_ai_applied']);
        $this->assertArrayHasKey('checks_applied', $result['second_ai']);
        $this->assertContains('fact_check', $result['second_ai']['checks_applied']);
    }

    public function test_unified_mode_converts_to_legacy_format(): void
    {
        $unifiedResponse = json_encode([
            'passed' => true,
            'modifications' => [
                'fact_check' => [
                    'required' => false,
                    'confidence' => 1.0,
                    'claims_extracted' => [],
                    'unverified_claims' => [],
                    'rewritten' => null,
                ],
                'policy' => [
                    'required' => false,
                    'confidence' => 1.0,
                    'violations' => [],
                    'rewritten' => null,
                ],
            ],
            'final_response' => 'We have 1 million verified users on our platform.',
        ]);

        // Mock OpenRouterService via chat()
        $this->mock(OpenRouterService::class, function ($mock) use ($unifiedResponse) {
            $mock->shouldReceive('chat')
                ->andReturn([
                    'content' => $unifiedResponse,
                    'model' => 'openai/gpt-4o-mini',
                    'usage' => [],
                ]);
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('getFlowKnowledgeBaseContext')->andReturn('');
        });

        $service = app(SecondAIService::class);

        $result = $service->process(
            response: 'We have 1 million verified users on our platform currently.',
            flow: $this->flow,
            userMessage: 'How many users?'
        );

        // Verify legacy format structure
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('second_ai_applied', $result);
        $this->assertArrayHasKey('second_ai', $result);
        $this->assertArrayHasKey('checks_applied', $result['second_ai']);
        $this->assertArrayHasKey('modifications', $result['second_ai']);
        $this->assertArrayHasKey('elapsed_ms', $result['second_ai']);
    }

    public function test_sequential_mode_when_only_one_check_enabled(): void
    {
        // Update flow to have only 1 check enabled
        $flow = Flow::factory()->create([
            'bot_id' => $this->bot->id,
        ]);

        // Update bot options
        $this->bot->update([
            'second_ai_options' => [
                'fact_check' => true,
                'policy' => false,
                'personality' => false,
            ],
        ]);

        // UnifiedCheckService should NOT be called
        $this->mock(UnifiedCheckService::class, function ($mock) {
            $mock->shouldNotReceive('check');
        });

        // Mock services for sequential mode
        $this->mock(OpenRouterService::class);
        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn([]);
        });

        $service = app(SecondAIService::class);

        $result = $service->process(
            response: 'สินค้า E ราคา 1,599 บาท มีหลายสีให้เลือก พร้อมส่งฟรีค่ะ',
            flow: $flow,
            userMessage: 'Test message'
        );

        // Should still work via sequential mode
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('second_ai_applied', $result);
    }
}
