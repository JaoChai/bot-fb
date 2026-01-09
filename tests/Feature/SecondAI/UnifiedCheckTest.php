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
        $this->bot = Bot::factory()->create();

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
        // Mock OpenRouterService to return valid unified response
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('generateText')
                ->andReturn(json_encode([
                    'passed' => false,
                    'modifications' => [
                        'fact_check' => [
                            'required' => true,
                            'claims_extracted' => ['We have 1M users'],
                            'unverified_claims' => [],
                            'rewritten' => 'We have 1M users.',
                        ],
                        'policy' => [
                            'required' => false,
                            'violations' => [],
                            'rewritten' => null,
                        ],
                    ],
                    'final_response' => 'We have 1M users.',
                ]));
        });

        // Mock RAGService
        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn([]);
        });

        $service = app(SecondAIService::class);

        $result = $service->process(
            response: 'We have 1M users.',
            flow: $this->flow,
            userMessage: 'How many users do you have?'
        );

        $this->assertEquals('We have 1M users.', $result['content']);
        $this->assertTrue($result['second_ai_applied']);
        $this->assertArrayHasKey('checks_applied', $result['second_ai']);
        $this->assertContains('fact_check', $result['second_ai']['checks_applied']);
    }

    public function test_unified_mode_converts_to_legacy_format(): void
    {
        // Mock OpenRouterService
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('generateText')
                ->andReturn(json_encode([
                    'passed' => true,
                    'modifications' => [
                        'fact_check' => [
                            'required' => false,
                            'claims_extracted' => [],
                            'unverified_claims' => [],
                            'rewritten' => null,
                        ],
                        'policy' => [
                            'required' => false,
                            'violations' => [],
                            'rewritten' => null,
                        ],
                    ],
                    'final_response' => 'We have 1M verified users.',
                ]));
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn([]);
        });

        $service = app(SecondAIService::class);

        $result = $service->process(
            response: 'We have 1M verified users.',
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
            response: 'Test response',
            flow: $flow,
            userMessage: 'Test message'
        );

        // Should still work via sequential mode
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('second_ai_applied', $result);
    }
}
