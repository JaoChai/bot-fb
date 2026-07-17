<?php

namespace Tests\Unit\Services;

use App\Exceptions\OpenRouterException;
use App\Services\ModelCapabilityService;
use App\Services\OpenRouterService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
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
            'services.openrouter.site_url' => 'http://localhost',
            'services.openrouter.site_name' => 'TestApp',
            'services.openrouter.timeout' => 60,
            'services.openrouter.max_tokens' => 4096,
        ]);

        $this->service = app(OpenRouterService::class);
    }

    public function test_service_is_configured_when_api_key_present(): void
    {
        $this->assertTrue($this->service->isConfigured());
    }

    public function test_service_is_not_configured_when_api_key_missing(): void
    {
        config(['services.openrouter.api_key' => '']);
        $service = app(OpenRouterService::class);

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

        $result = $this->service->chat(
            [['role' => 'user', 'content' => 'Hello']],
            'anthropic/claude-3.5-sonnet'
        );

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

        $result = $this->service->chat(
            [['role' => 'user', 'content' => 'Hello']],
            'anthropic/claude-3.5-sonnet',
            fallbackModelOverride: 'openai/gpt-4o-mini'
        );

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
            'anthropic/claude-3.5-sonnet',
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

        $this->service->chat(
            [['role' => 'user', 'content' => 'Hello']],
            'anthropic/claude-3.5-sonnet'
        );
    }

    public function test_chat_falls_back_client_side_when_primary_times_out(): void
    {
        // A read-timeout on the primary raises ConnectionException and aborts before
        // OpenRouter's server-side fallback can engage — chat() must retry the fallback
        // model directly, client-side, so the caller still gets an answer.
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => []], 200),
            'openrouter.ai/api/v1/chat/completions' => function ($request) {
                // Fallback attempt sends a single `model` (no `models` array) → succeed.
                if (($request->data()['model'] ?? null) === 'google/gemini-flash') {
                    return Http::response([
                        'id' => 'gen-fb',
                        'model' => 'google/gemini-flash',
                        'choices' => [
                            ['message' => ['content' => 'Answer from fallback'], 'finish_reason' => 'stop'],
                        ],
                        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
                    ], 200);
                }

                // Primary attempt (models array) → simulate the 45s read-timeout.
                throw new ConnectionException('Operation timed out after 45000 milliseconds');
            },
        ]);

        $result = $this->service->chat(
            [['role' => 'user', 'content' => 'Hello']],
            'openai/gpt-4o',
            fallbackModelOverride: 'google/gemini-flash'
        );

        $this->assertEquals('Answer from fallback', $result['content']);
        $this->assertEquals('google/gemini-flash', $result['model']);

        // The fallback was requested as a single model, not via the server-side models array.
        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['model'] ?? null) === 'google/gemini-flash' && ! isset($body['models']);
        });
    }

    public function test_client_side_fallback_does_not_inherit_high_reasoning(): void
    {
        // Primary reasoning model gets 'high'; it times out; the fallback is ALSO a
        // reasoning model but must fall back to ITS OWN default effort (medium), never 'high'.
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => [
                ['id' => 'openai/o1', 'supported_parameters' => ['reasoning']],
                ['id' => 'openai/o1-mini', 'supported_parameters' => ['reasoning']],
            ]], 200),
            'openrouter.ai/api/v1/chat/completions' => function ($request) {
                if (($request->data()['model'] ?? null) === 'openai/o1-mini') {
                    return Http::response([
                        'id' => 'fb', 'model' => 'openai/o1-mini',
                        'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                    ], 200);
                }
                throw new ConnectionException('Operation timed out');
            },
        ]);

        $this->service->chat(
            [['role' => 'user', 'content' => 'hi']],
            'openai/o1',
            fallbackModelOverride: 'openai/o1-mini',
            reasoning: ['effort' => 'high'],
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            if (($body['model'] ?? null) !== 'openai/o1-mini') {
                return false;
            }

            // fallback must NOT carry the caller's 'high'; it uses o1-mini's own default (medium)
            return ($body['reasoning']['effort'] ?? null) === 'medium';
        });
    }

    public function test_chat_throws_when_no_model_provided(): void
    {
        $this->expectException(OpenRouterException::class);

        $this->service->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }

    public function test_chat_sends_single_model_when_no_fallback_configured(): void
    {
        // Fallback comes ONLY from bot settings — no config substitution
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-nofb',
                'model' => 'anthropic/claude-3.5-sonnet',
                'choices' => [
                    ['message' => ['content' => 'No fallback'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
            ], 200),
        ]);

        $this->service->chat(
            [['role' => 'user', 'content' => 'Hello']],
            'anthropic/claude-3.5-sonnet'
            // useFallback defaults to true, but no fallbackModelOverride given
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['model']) &&
                   $body['model'] === 'anthropic/claude-3.5-sonnet' &&
                   ! isset($body['models']);
        });
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

        $result = $this->service->chatSimple(
            [['role' => 'user', 'content' => 'Hello']],
            'anthropic/claude-3.5-sonnet'
        );

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
            [],
            'anthropic/claude-3.5-sonnet'
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
            $history,
            'anthropic/claude-3.5-sonnet'
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            if (! isset($body['messages'])) {
                return false;
            }
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

    // -------------------------------------------------------------------------
    // Capability Delegation Tests
    //
    // Lock the delegation contract before Task B2 converts service-locator
    // (app(MCS::class)) to constructor injection. Tests bind a mock into the
    // container, which works for both lookup patterns.
    // -------------------------------------------------------------------------

    public function test_supports_vision_delegates_to_model_capability_service(): void
    {
        $mockCapability = Mockery::mock(ModelCapabilityService::class);
        $mockCapability->shouldReceive('supportsVision')
            ->once()
            ->with('openai/gpt-4o')
            ->andReturn(true);
        $this->app->instance(ModelCapabilityService::class, $mockCapability);
        $this->service = app(OpenRouterService::class);

        $result = $this->service->supportsVision('openai/gpt-4o');

        $this->assertTrue($result);
    }

    public function test_supports_reasoning_delegates_to_model_capability_service(): void
    {
        $mockCapability = Mockery::mock(ModelCapabilityService::class);
        $mockCapability->shouldReceive('supportsReasoning')
            ->once()
            ->with('anthropic/claude-3.5-sonnet')
            ->andReturn(false);
        $this->app->instance(ModelCapabilityService::class, $mockCapability);
        $this->service = app(OpenRouterService::class);

        $result = $this->service->supportsReasoning('anthropic/claude-3.5-sonnet');

        $this->assertFalse($result);
    }

    public function test_supports_structured_output_delegates_to_model_capability_service(): void
    {
        $mockCapability = Mockery::mock(ModelCapabilityService::class);
        $mockCapability->shouldReceive('supportsStructuredOutput')
            ->once()
            ->with('openai/gpt-4o-mini')
            ->andReturn(true);
        $this->app->instance(ModelCapabilityService::class, $mockCapability);
        $this->service = app(OpenRouterService::class);

        $result = $this->service->supportsStructuredOutput('openai/gpt-4o-mini');

        $this->assertTrue($result);
    }

    public function test_is_mandatory_reasoning_delegates_to_model_capability_service(): void
    {
        $mockCapability = Mockery::mock(ModelCapabilityService::class);
        $mockCapability->shouldReceive('isMandatoryReasoning')
            ->once()
            ->with('openai/o1-preview')
            ->andReturn(true);
        $this->app->instance(ModelCapabilityService::class, $mockCapability);
        $this->service = app(OpenRouterService::class);

        $result = $this->service->isMandatoryReasoning('openai/o1-preview');

        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // chatWithTools Tests
    // -------------------------------------------------------------------------

    public function test_chat_with_tools_builds_tools_array_in_request_body(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-tools-1',
                'model' => 'anthropic/claude-3.5-sonnet',
                'choices' => [
                    [
                        'message' => ['content' => 'Using a tool'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 6, 'total_tokens' => 18],
            ], 200),
        ]);

        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get the current weather',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => ['type' => 'string'],
                        ],
                        'required' => ['location'],
                    ],
                ],
            ],
        ];

        $this->service->chatWithTools(
            [['role' => 'user', 'content' => 'What is the weather?']],
            $tools,
            'anthropic/claude-3.5-sonnet'
        );

        Http::assertSent(function ($request) use ($tools) {
            $body = $request->data();

            return str_contains($request->url(), '/chat/completions') &&
                   isset($body['tools']) &&
                   $body['tools'] === $tools &&
                   isset($body['tool_choice']) &&
                   $body['tool_choice'] === 'auto';
        });
    }

    // -------------------------------------------------------------------------
    // buildVisionMessages Tests
    // -------------------------------------------------------------------------

    public function test_build_vision_messages_attaches_images_only_to_last_user_message(): void
    {
        $method = new \ReflectionMethod(OpenRouterService::class, 'buildVisionMessages');

        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
            ['role' => 'user', 'content' => 'Look at this image'],
        ];
        $imageUrls = ['https://example.com/image.jpg'];

        $result = $method->invoke($this->service, $messages, $imageUrls);

        // System message unchanged
        $this->assertEquals('system', $result[0]['role']);
        $this->assertEquals('You are helpful.', $result[0]['content']);

        // First user message unchanged (plain string)
        $this->assertEquals('user', $result[1]['role']);
        $this->assertIsString($result[1]['content']);
        $this->assertEquals('Hello', $result[1]['content']);

        // Assistant message unchanged
        $this->assertEquals('assistant', $result[2]['role']);
        $this->assertEquals('Hi there!', $result[2]['content']);

        // Last user message is multimodal with image
        $this->assertEquals('user', $result[3]['role']);
        $this->assertIsArray($result[3]['content']);
        $this->assertCount(2, $result[3]['content']);
        $this->assertEquals('text', $result[3]['content'][0]['type']);
        $this->assertEquals('Look at this image', $result[3]['content'][0]['text']);
        $this->assertEquals('image_url', $result[3]['content'][1]['type']);
        $this->assertEquals('https://example.com/image.jpg', $result[3]['content'][1]['image_url']['url']);
    }

    public function test_build_vision_messages_returns_unchanged_when_no_images(): void
    {
        $method = new \ReflectionMethod(OpenRouterService::class, 'buildVisionMessages');

        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $result = $method->invoke($this->service, $messages, []);

        $this->assertEquals($messages, $result);
    }

    public function test_build_vision_messages_works_with_single_user_message(): void
    {
        $method = new \ReflectionMethod(OpenRouterService::class, 'buildVisionMessages');

        $messages = [
            ['role' => 'user', 'content' => 'Analyze this'],
        ];
        $imageUrls = ['https://example.com/photo.png'];

        $result = $method->invoke($this->service, $messages, $imageUrls);

        $this->assertIsArray($result[0]['content']);
        $this->assertEquals('text', $result[0]['content'][0]['type']);
        $this->assertEquals('image_url', $result[0]['content'][1]['type']);
    }

    public function test_build_vision_messages_handles_multiple_images(): void
    {
        $method = new \ReflectionMethod(OpenRouterService::class, 'buildVisionMessages');

        $messages = [
            ['role' => 'user', 'content' => 'Compare these'],
        ];
        $imageUrls = ['https://example.com/a.jpg', 'https://example.com/b.jpg'];

        $result = $method->invoke($this->service, $messages, $imageUrls);

        // text + 2 images = 3 content items
        $this->assertCount(3, $result[0]['content']);
        $this->assertEquals('text', $result[0]['content'][0]['type']);
        $this->assertEquals('image_url', $result[0]['content'][1]['type']);
        $this->assertEquals('image_url', $result[0]['content'][2]['type']);
        $this->assertEquals('https://example.com/a.jpg', $result[0]['content'][1]['image_url']['url']);
        $this->assertEquals('https://example.com/b.jpg', $result[0]['content'][2]['image_url']['url']);
    }

    public function test_build_vision_messages_returns_unchanged_when_no_user_messages(): void
    {
        $method = new \ReflectionMethod(OpenRouterService::class, 'buildVisionMessages');

        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'assistant', 'content' => 'Hello!'],
        ];
        $imageUrls = ['https://example.com/image.jpg'];

        $result = $method->invoke($this->service, $messages, $imageUrls);

        // No user messages to attach images to, return unchanged
        $this->assertEquals($messages, $result);
    }

    public function test_generate_bot_response_sends_effort_only_for_reasoning_models(): void
    {
        // reasoning model → effort ถูกส่ง
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

        $this->service->generateBotResponse(
            userMessage: 'hi',
            model: 'openai/o1',
            reasoning: ['effort' => 'high'],
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            return ($request->data()['reasoning']['effort'] ?? null) === 'high';
        });
    }

    public function test_generate_bot_response_omits_reasoning_for_non_reasoning_model(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => [
                ['id' => 'google/gemini-2.0-flash-001', 'supported_parameters' => []],
            ]], 200),
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'g', 'model' => 'google/gemini-2.0-flash-001',
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $this->service->generateBotResponse(
            userMessage: 'hi',
            model: 'google/gemini-2.0-flash-001',
            reasoning: ['effort' => 'high'],
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            return ! isset($request->data()['reasoning']);
        });
    }
}
