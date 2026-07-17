<?php

// backend/tests/Feature/RAG/ReasoningEffortWiringTest.php

namespace Tests\Feature\RAG;

use App\Models\Bot;
use App\Services\RAGService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReasoningEffortWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_effort_reaches_llm_payload(): void
    {
        // bot=medium → adaptive คงเป็น medium ไม่ว่าข้อความ complex หรือไม่ → assertion deterministic
        config(['services.openrouter.api_key' => 'k']);
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => [
                ['id' => 'openai/o1', 'supported_parameters' => ['reasoning']],
            ]], 200),
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'g', 'model' => 'openai/o1',
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $bot = Bot::factory()->create([
            'primary_chat_model' => 'openai/o1',
            'reasoning_effort' => 'medium',
        ]);

        app(RAGService::class)->generateResponse(bot: $bot, userMessage: 'สวัสดี');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            return ($request->data()['reasoning']['effort'] ?? null) === 'medium';
        });
    }
}
