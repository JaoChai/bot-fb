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
        // Mock OpenRouterService to return invalid JSON from chat() (unified mode)
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->andReturn([
                    'content' => 'This is not valid JSON at all, sorry I cannot help.',
                    'model' => 'openai/gpt-4o-mini',
                ]);
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('getFlowKnowledgeBaseContext')->andReturn('');
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->with('SecondAI: Unified mode failed, falling back to sequential', \Mockery::any());
        Log::shouldReceive('error')->andReturnSelf();

        $service = app(SecondAIService::class);

        // Response must be >50 chars or contain digits to bypass skip logic
        $result = $service->process(
            response: 'สินค้า A รุ่นใหม่ล่าสุด ราคาพิเศษ 1,299 บาท พร้อมส่งฟรีทั่วประเทศค่ะ',
            flow: $this->flow,
            userMessage: 'Test message'
        );

        // Should fallback gracefully
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('second_ai_applied', $result);
    }

    public function test_fallback_when_unified_throws_exception(): void
    {
        // Mock OpenRouterService to throw exception from chat()
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->andThrow(new \RuntimeException('LLM API timeout'));
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('getFlowKnowledgeBaseContext')->andReturn('');
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->with('SecondAI: Unified mode failed, falling back to sequential', \Mockery::any());
        Log::shouldReceive('error')->andReturnSelf();

        $service = app(SecondAIService::class);

        // Response must be >50 chars or contain digits to bypass skip logic
        $result = $service->process(
            response: 'สินค้า B รุ่นพิเศษ ราคา 2,499 บาท รับประกัน 1 ปีเต็ม พร้อมส่งฟรีค่ะ',
            flow: $this->flow,
            userMessage: 'Test message'
        );

        // Should fallback and use sequential mode
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('second_ai_applied', $result);
    }

    public function test_fallback_when_missing_required_fields(): void
    {
        // Mock OpenRouterService to return JSON missing required fields via chat()
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->andReturn([
                    'content' => json_encode([
                        'passed' => true,
                        // Missing 'modifications' and 'final_response'
                    ]),
                    'model' => 'openai/gpt-4o-mini',
                ]);
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('getFlowKnowledgeBaseContext')->andReturn('');
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnSelf();
        Log::shouldReceive('error')->andReturnSelf();

        $service = app(SecondAIService::class);

        // Response must bypass skip logic (contains digits)
        $result = $service->process(
            response: 'สินค้า C ราคา 899 บาท มีโปรโมชั่นลด 10% สำหรับสมาชิกค่ะ',
            flow: $this->flow,
            userMessage: 'Test message'
        );

        // Should fallback gracefully
        $this->assertArrayHasKey('content', $result);
    }

    public function test_original_response_returned_on_complete_failure(): void
    {
        // Mock both unified and sequential to fail via chat()
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->andThrow(new \Exception('Complete API failure'));
        });

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('getFlowKnowledgeBaseContext')->andReturn('');
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnSelf();
        Log::shouldReceive('error')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnSelf();

        $service = app(SecondAIService::class);

        // Response must bypass skip logic (contains digits)
        $originalResponse = 'สินค้า D ราคาพิเศษ 3,999 บาท รับประกัน 2 ปี พร้อมส่งฟรีทั่วประเทศค่ะ';

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
