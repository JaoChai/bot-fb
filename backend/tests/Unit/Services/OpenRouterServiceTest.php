<?php

namespace Tests\Unit\Services;

use App\Exceptions\OpenRouterException;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterServiceTest extends TestCase
{
    protected OpenRouterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouter.api_key' => 'test-api-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.default_model' => 'anthropic/claude-3.5-sonnet',
            'services.openrouter.fallback_model' => 'openai/gpt-4o-mini',
            'services.openrouter.site_url' => 'http://localhost',
            'services.openrouter.site_name' => 'TestApp',
            'services.openrouter.timeout' => 60,
            'services.openrouter.max_tokens' => 4096,
        ]);

        $this->service = new OpenRouterService();
    }

    public function test_service_is_configured_when_api_key_present(): void
    {
        $this->assertTrue($this->service->isConfigured());
    }

    public function test_service_is_not_configured_when_api_key_missing(): void
    {
        config(['services.openrouter.api_key' => '']);
        $service = new OpenRouterService();

        $this->assertFalse($service->isConfigured());
    }

    public function test_chat_returns_response_on_success(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-123',
                'model' => 'anthropic/claude-3.5-sonnet',
                'choices' => [
                    [
                        'message' => ['content' => 'Hello! How can I help you?'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 8,
                    'total_tokens' => 18,
                ],
            ], 200),
        ]);

        $result = $this->service->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertEquals('Hello! How can I help you?', $result['content']);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $result['model']);
        $this->assertEquals(10, $result['usage']['prompt_tokens']);
        $this->assertEquals(8, $result['usage']['completion_tokens']);
    }

    public function test_chat_uses_native_fallback_with_models_array(): void
    {
        // With native fallback, OpenRouter handles server-side fallback
        // We send models array and get response with the model that was actually used
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-456',
                'model' => 'openai/gpt-4o-mini', // OpenRouter used fallback
                'choices' => [
                    ['message' => ['content' => 'Fallback response'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
            ], 200),
        ]);

        $result = $this->service->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        // Verify models array was sent (native fallback)
        Http::assertSent(function ($request) {
            $body = $request->data();

            // Should use models array instead of single model
            return isset($body['models']) &&
                   $body['models'] === ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o-mini'];
        });

        $this->assertEquals('Fallback response', $result['content']);
        $this->assertEquals('openai/gpt-4o-mini', $result['model']);
    }

    public function test_chat_uses_single_model_when_fallback_disabled(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-789',
                'model' => 'anthropic/claude-3.5-sonnet',
                'choices' => [
                    ['message' => ['content' => 'Direct response'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
            ], 200),
        ]);

        $result = $this->service->chat(
            [['role' => 'user', 'content' => 'Hello']],
            null,
            null,
            null,
            false // useFallback = false
        );

        // Verify single model was sent (no fallback)
        Http::assertSent(function ($request) {
            $body = $request->data();

            // Should use model string, not models array
            return isset($body['model']) &&
                   $body['model'] === 'anthropic/claude-3.5-sonnet' &&
                   ! isset($body['models']);
        });

        $this->assertEquals('Direct response', $result['content']);
    }

    public function test_chat_throws_exception_when_all_models_fail(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Service unavailable'],
            ], 503),
        ]);

        $this->expectException(OpenRouterException::class);

        $this->service->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }

    public function test_chat_simple_returns_content_string(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Simple response'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
            ], 200),
        ]);

        $result = $this->service->chatSimple([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertEquals('Simple response', $result);
    }

    public function test_generate_bot_response_includes_system_prompt(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Bot response'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25],
            ], 200),
        ]);

        $result = $this->service->generateBotResponse(
            'Hello',
            'You are a helpful assistant.',
            []
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['messages']) &&
                   $body['messages'][0]['role'] === 'system' &&
                   $body['messages'][0]['content'] === 'You are a helpful assistant.' &&
                   $body['messages'][1]['role'] === 'user' &&
                   $body['messages'][1]['content'] === 'Hello';
        });

        $this->assertEquals('Bot response', $result['content']);
    }

    public function test_generate_bot_response_includes_conversation_history(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Response with context'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5, 'total_tokens' => 35],
            ], 200),
        ]);

        $history = [
            ['sender' => 'user', 'content' => 'Hi'],
            ['sender' => 'bot', 'content' => 'Hello!'],
        ];

        $this->service->generateBotResponse(
            'How are you?',
            null,
            $history
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $messages = $body['messages'];

            return count($messages) === 3 &&
                   $messages[0]['content'] === 'Hi' &&
                   $messages[1]['content'] === 'Hello!' &&
                   $messages[2]['content'] === 'How are you?';
        });
    }

    public function test_estimate_cost_calculates_correctly(): void
    {
        // Claude 3.5 Sonnet: $3/1M prompt, $15/1M completion
        $cost = $this->service->estimateCost(1000, 500, 'anthropic/claude-3.5-sonnet');

        // 1000 tokens * $3/1M = $0.003
        // 500 tokens * $15/1M = $0.0075
        // Total = $0.0105
        $this->assertEquals(0.0105, $cost);
    }

    public function test_estimate_cost_uses_default_for_unknown_model(): void
    {
        $cost = $this->service->estimateCost(1000, 500, 'unknown/model');

        // Default: $1/1M prompt, $2/1M completion
        // 1000 * $1/1M = $0.001
        // 500 * $2/1M = $0.001
        // Total = $0.002
        $this->assertEquals(0.002, $cost);
    }

    public function test_list_models_returns_array(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    ['id' => 'anthropic/claude-3.5-sonnet', 'name' => 'Claude 3.5 Sonnet'],
                    ['id' => 'openai/gpt-4o', 'name' => 'GPT-4o'],
                ],
            ], 200),
        ]);

        $models = $this->service->listModels();

        $this->assertCount(2, $models);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $models[0]['id']);
    }

    public function test_get_model_returns_model_info(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    ['id' => 'anthropic/claude-3.5-sonnet', 'name' => 'Claude 3.5 Sonnet'],
                    ['id' => 'openai/gpt-4o', 'name' => 'GPT-4o'],
                ],
            ], 200),
        ]);

        $model = $this->service->getModel('openai/gpt-4o');

        $this->assertEquals('GPT-4o', $model['name']);
    }

    public function test_get_model_returns_null_for_unknown(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    ['id' => 'anthropic/claude-3.5-sonnet', 'name' => 'Claude 3.5 Sonnet'],
                ],
            ], 200),
        ]);

        $model = $this->service->getModel('unknown/model');

        $this->assertNull($model);
    }
}
