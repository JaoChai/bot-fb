<?php

namespace Tests\Feature\SecondAI;

use App\Models\Bot;
use App\Models\Flow;
use App\Services\OpenRouterService;
use App\Services\RAGService;
use App\Services\SecondAI\SecondAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FallbackTest extends TestCase
{
    use RefreshDatabase;

    protected Flow $flow;

    protected Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bot = Bot::factory()->create();

        $this->flow = Flow::factory()->create([
            'bot_id' => $this->bot->id,
            'second_ai_enabled' => true,
            'second_ai_options' => [
                'fact_check' => true,
                'policy' => true,
                'personality' => true,
            ],
        ]);
    }

    public function test_fallback_to_sequential_when_unified_returns_invalid_json(): void
    {
        // Mock OpenRouterService to return invalid JSON on first call (unified mode)
        // Then valid responses for sequential mode
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('generateText')
                ->andReturn('This is not valid JSON');  // Invalid response for unified mode

            // Sequential mode calls would happen here but we'll skip for simplicity
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn([]);
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->with('SecondAI: Unified mode failed, falling back to sequential', \Mockery::any());
        Log::shouldReceive('error')->andReturnSelf();

        $service = app(SecondAIService::class);

        $result = $service->process(
            response: 'Original response',
            flow: $this->flow,
            userMessage: 'Test message'
        );

        // Should fallback gracefully
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('second_ai_applied', $result);
    }

    public function test_fallback_when_unified_throws_exception(): void
    {
        // Mock OpenRouterService to throw exception
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('generateText')
                ->andThrow(new \RuntimeException('LLM API timeout'));
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn([]);
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->with('SecondAI: Unified mode failed, falling back to sequential', \Mockery::any());
        Log::shouldReceive('error')->andReturnSelf();

        $service = app(SecondAIService::class);

        $result = $service->process(
            response: 'Original response',
            flow: $this->flow,
            userMessage: 'Test message'
        );

        // Should fallback and use sequential mode
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('second_ai_applied', $result);
    }

    public function test_fallback_when_missing_required_fields(): void
    {
        // Mock OpenRouterService to return JSON missing required fields
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('generateText')
                ->andReturn(json_encode([
                    'passed' => true,
                    // Missing 'modifications' and 'final_response'
                ]));
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn([]);
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnSelf();
        Log::shouldReceive('error')->andReturnSelf();

        $service = app(SecondAIService::class);

        $result = $service->process(
            response: 'Original response',
            flow: $this->flow,
            userMessage: 'Test message'
        );

        // Should fallback gracefully
        $this->assertArrayHasKey('content', $result);
    }

    public function test_original_response_returned_on_complete_failure(): void
    {
        // Mock both unified and sequential to fail
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('generateText')
                ->andThrow(new \Exception('Complete API failure'));
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn([]);
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnSelf();
        Log::shouldReceive('error')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnSelf();

        $service = app(SecondAIService::class);

        $originalResponse = 'This is the original response';

        $result = $service->process(
            response: $originalResponse,
            flow: $this->flow,
            userMessage: 'Test message'
        );

        // Should return original response on complete failure
        // With per-check rescue, individual check failures are caught gracefully
        // so the pipeline completes successfully with unmodified content
        $this->assertEquals($originalResponse, $result['content']);
    }
}
