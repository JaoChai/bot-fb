<?php

namespace Tests\Unit\Services;

use App\Services\CircuitBreakerService;
use App\Services\ModelCapabilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ModelCapabilityServiceTest extends TestCase
{
    protected ModelCapabilityService $service;

    protected CircuitBreakerService $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();

        // Create a mock circuit breaker that executes operations directly
        $this->circuitBreaker = Mockery::mock(CircuitBreakerService::class);
        $this->circuitBreaker->shouldReceive('execute')
            ->andReturnUsing(function ($service, $operation, $fallback = null) {
                try {
                    return $operation();
                } catch (\Throwable $e) {
                    return $fallback ? $fallback() : null;
                }
            });

        $this->service = new ModelCapabilityService($this->circuitBreaker);
    }

    // -------------------------------------------------------------------------
    // Vision Detection Tests
    // -------------------------------------------------------------------------

    public function test_supports_vision_returns_true_for_gpt4o_from_config(): void
    {
        // Use config fallback (no API key set)
        config(['services.openrouter.api_key' => null]);

        $result = $this->service->supportsVision('openai/gpt-4o');

        $this->assertTrue($result);
    }

    public function test_supports_vision_returns_true_for_gemini_models_from_config(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->assertTrue($this->service->supportsVision('google/gemini-flash-1.5'));
        $this->assertTrue($this->service->supportsVision('google/gemini-pro-1.5'));
        $this->assertTrue($this->service->supportsVision('google/gemini-2.0-flash-exp'));
    }

    public function test_supports_vision_returns_true_for_claude_3_models_from_config(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->assertTrue($this->service->supportsVision('anthropic/claude-3-haiku'));
        $this->assertTrue($this->service->supportsVision('anthropic/claude-3-opus'));
        $this->assertTrue($this->service->supportsVision('anthropic/claude-3.5-sonnet'));
        $this->assertTrue($this->service->supportsVision('anthropic/claude-sonnet-4'));
    }

    public function test_supports_vision_returns_false_for_text_only_models(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->assertFalse($this->service->supportsVision('meta-llama/llama-3.1-70b-instruct'));
        $this->assertFalse($this->service->supportsVision('mistralai/mistral-large'));
        $this->assertFalse($this->service->supportsVision('deepseek/deepseek-chat'));
    }

    public function test_unknown_model_returns_false_for_vision(): void
    {
        config(['services.openrouter.api_key' => null]);

        // Unknown model not in config/API should return false (no guessing)
        $this->assertFalse($this->service->supportsVision('unknown/text-only-model'));
    }

    // -------------------------------------------------------------------------
    // Context Length Tests
    // -------------------------------------------------------------------------

    public function test_get_context_length_from_config(): void
    {
        config(['services.openrouter.api_key' => null]);

        $contextLength = $this->service->getContextLength('openai/gpt-4o');

        $this->assertEquals(128000, $contextLength);
    }

    public function test_get_context_length_for_gemini(): void
    {
        config(['services.openrouter.api_key' => null]);

        $contextLength = $this->service->getContextLength('google/gemini-flash-1.5');

        $this->assertEquals(1000000, $contextLength);
    }

    public function test_get_context_length_defaults_to_4096_for_unknown(): void
    {
        config(['services.openrouter.api_key' => null]);

        $contextLength = $this->service->getContextLength('unknown/model');

        $this->assertEquals(4096, $contextLength);
    }

    // -------------------------------------------------------------------------
    // Max Output Tokens Tests
    // -------------------------------------------------------------------------

    public function test_get_max_output_tokens_from_config(): void
    {
        config(['services.openrouter.api_key' => null]);

        $maxTokens = $this->service->getMaxOutputTokens('openai/gpt-4o');

        $this->assertEquals(16384, $maxTokens);
    }

    public function test_get_max_output_tokens_defaults_to_4096_for_unknown(): void
    {
        config(['services.openrouter.api_key' => null]);

        $maxTokens = $this->service->getMaxOutputTokens('unknown/model');

        $this->assertEquals(4096, $maxTokens);
    }

    // -------------------------------------------------------------------------
    // Pricing Tests
    // -------------------------------------------------------------------------

    public function test_get_pricing_from_config(): void
    {
        config(['services.openrouter.api_key' => null]);

        $pricing = $this->service->getPricing('openai/gpt-4o');

        $this->assertEquals(2.5, $pricing['prompt']);
        $this->assertEquals(10.0, $pricing['completion']);
    }

    public function test_get_pricing_defaults_to_zero_for_unknown(): void
    {
        config(['services.openrouter.api_key' => null]);

        $pricing = $this->service->getPricing('unknown/model');

        $this->assertEquals(0.0, $pricing['prompt']);
        $this->assertEquals(0.0, $pricing['completion']);
    }

    // -------------------------------------------------------------------------
    // Cache Tests
    // -------------------------------------------------------------------------

    public function test_capabilities_are_cached(): void
    {
        config(['services.openrouter.api_key' => null]);

        // First call
        $this->service->getCapabilities('openai/gpt-4o');

        // Verify cached
        $cacheKey = 'model_cap:openai_gpt-4o';
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_cache_is_used_on_second_call(): void
    {
        config(['services.openrouter.api_key' => null]);

        // First call - populates cache
        $capabilities1 = $this->service->getCapabilities('openai/gpt-4o');

        // Modify cache to verify it's being used
        $cacheKey = 'model_cap:openai_gpt-4o';
        $modifiedCapabilities = $capabilities1;
        $modifiedCapabilities['test_marker'] = 'cached_value';
        Cache::put($cacheKey, $modifiedCapabilities, 3600);

        // Second call - should use cache
        $capabilities2 = $this->service->getCapabilities('openai/gpt-4o');

        $this->assertEquals('cached_value', $capabilities2['test_marker']);
    }

    public function test_invalidate_cache_clears_model_cache(): void
    {
        config(['services.openrouter.api_key' => null]);

        // Populate cache
        $this->service->getCapabilities('openai/gpt-4o');

        $cacheKey = 'model_cap:openai_gpt-4o';
        $this->assertTrue(Cache::has($cacheKey));

        // Invalidate
        $this->service->invalidateCache('openai/gpt-4o');

        $this->assertFalse(Cache::has($cacheKey));
    }

    // -------------------------------------------------------------------------
    // API Fallback Tests
    // -------------------------------------------------------------------------

    public function test_fallback_to_config_when_api_fails(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);

        // Mock HTTP to fail
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        $capabilities = $this->service->getCapabilities('openai/gpt-4o');

        // Should fallback to config
        $this->assertEquals('config', $capabilities['source']);
        $this->assertTrue($capabilities['supports_vision']);
    }

    public function test_fallback_to_default_when_api_and_config_fail(): void
    {
        config(['services.openrouter.api_key' => null]);

        // Unknown model not in config
        $capabilities = $this->service->getCapabilities('unknown/new-model');

        // Should use default (no guessing)
        $this->assertEquals('default', $capabilities['source']);
        $this->assertFalse($capabilities['supports_vision']); // No guessing - default to false
    }

    // -------------------------------------------------------------------------
    // API Success Tests
    // -------------------------------------------------------------------------

    public function test_fetch_from_api_when_available(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);

        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'openai/gpt-4o',
                        'name' => 'GPT-4o',
                        'description' => 'Vision-capable model',
                        'context_length' => 128000,
                        'architecture' => [
                            'modality' => 'text+image',
                            'max_output_tokens' => 16384,
                        ],
                        'pricing' => [
                            'prompt' => 0.0000025,
                            'completion' => 0.00001,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $capabilities = $this->service->getCapabilities('openai/gpt-4o');

        // openai/gpt-4o is in both API and config, so source is merged
        $this->assertContains($capabilities['source'], ['api', 'api+config']);
        $this->assertTrue($capabilities['supports_vision']);
        $this->assertEquals(128000, $capabilities['context_length']);
    }

    // -------------------------------------------------------------------------
    // Capabilities Structure Tests
    // -------------------------------------------------------------------------

    public function test_get_capabilities_returns_complete_structure(): void
    {
        config(['services.openrouter.api_key' => null]);

        $capabilities = $this->service->getCapabilities('openai/gpt-4o');

        $this->assertArrayHasKey('model_id', $capabilities);
        $this->assertArrayHasKey('name', $capabilities);
        $this->assertArrayHasKey('supports_vision', $capabilities);
        $this->assertArrayHasKey('context_length', $capabilities);
        $this->assertArrayHasKey('max_output_tokens', $capabilities);
        $this->assertArrayHasKey('pricing_prompt', $capabilities);
        $this->assertArrayHasKey('pricing_completion', $capabilities);
        $this->assertArrayHasKey('source', $capabilities);
    }

    // -------------------------------------------------------------------------
    // Warm Cache Tests
    // -------------------------------------------------------------------------

    public function test_warm_cache_processes_all_models(): void
    {
        config(['services.openrouter.api_key' => null]);

        $result = $this->service->warmCache();

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertGreaterThan(0, $result['success']);
    }

    // -------------------------------------------------------------------------
    // Pattern-Based Vision Heuristic Tests (for unknown models)
    // -------------------------------------------------------------------------

    public function test_heuristic_detects_vision_for_new_gpt_models(): void
    {
        config(['services.openrouter.api_key' => null]);

        // These are NOT in config/llm-models.php
        $this->assertTrue($this->service->supportsVision('openai/gpt-5.4'));
        $this->assertTrue($this->service->supportsVision('openai/gpt-5.4-mini'));
        $this->assertTrue($this->service->supportsVision('openai/gpt-4.2'));
    }

    public function test_heuristic_detects_vision_for_new_claude_models(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->assertTrue($this->service->supportsVision('anthropic/claude-4-sonnet'));
        $this->assertTrue($this->service->supportsVision('anthropic/claude-sonnet-5'));
        $this->assertTrue($this->service->supportsVision('anthropic/claude-opus-5'));
    }

    public function test_heuristic_detects_vision_for_new_gemini_models(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->assertTrue($this->service->supportsVision('google/gemini-4.0-flash'));
        $this->assertTrue($this->service->supportsVision('google/gemini-ultra'));
    }

    public function test_heuristic_detects_vision_for_new_qwen_models(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->assertTrue($this->service->supportsVision('qwen/qwen3.6-plus'));
        $this->assertTrue($this->service->supportsVision('qwen/qwen3-vl-72b'));
    }

    public function test_heuristic_does_not_detect_vision_for_text_only_families(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->assertFalse($this->service->supportsVision('deepseek/deepseek-v3'));
        $this->assertFalse($this->service->supportsVision('mistralai/mistral-next'));
        $this->assertFalse($this->service->supportsVision('meta-llama/llama-3.3-70b'));
        $this->assertFalse($this->service->supportsVision('qwen/qwen-2.5-plus'));
    }

    public function test_heuristic_detects_vision_for_llama_4_plus(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->assertTrue($this->service->supportsVision('meta-llama/llama-4-maverick'));
        $this->assertFalse($this->service->supportsVision('meta-llama/llama-3.2-90b'));
    }

    public function test_unknown_model_returns_heuristic_source(): void
    {
        config(['services.openrouter.api_key' => null]);

        $capabilities = $this->service->getCapabilities('openai/gpt-5.4');

        $this->assertEquals('default+heuristic', $capabilities['source']);
        $this->assertTrue($capabilities['supports_vision']);
    }

    public function test_truly_unknown_model_returns_default_source(): void
    {
        config(['services.openrouter.api_key' => null]);

        $capabilities = $this->service->getCapabilities('unknown/text-only-model');

        $this->assertEquals('default', $capabilities['source']);
        $this->assertFalse($capabilities['supports_vision']);
    }

    // -------------------------------------------------------------------------
    // Case Insensitivity Tests
    // -------------------------------------------------------------------------

    public function test_model_id_normalization_is_case_insensitive(): void
    {
        config(['services.openrouter.api_key' => null]);

        // Different case variations should produce same cache key
        $this->service->getCapabilities('OpenAI/GPT-4o');

        $cacheKey = 'model_cap:openai_gpt-4o';
        $this->assertTrue(Cache::has($cacheKey));
    }

    protected function tearDown(): void
    {
        try {
            if (! Cache::isMocked()) {
                Cache::flush();
            }
        } catch (\Throwable $e) {
            // Ignore cache errors during teardown
        }

        Mockery::close();
        parent::tearDown();
    }
}
