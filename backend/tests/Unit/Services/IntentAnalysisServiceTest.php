<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\IntentAnalysisService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IntentAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    protected IntentAnalysisService $service;
    protected $mockOpenRouter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockOpenRouter = Mockery::mock(OpenRouterService::class);

        // Default mock expectations for new capability check methods
        // anthropic/claude-3-haiku is NOT a reasoning model and does NOT support structured output
        $this->mockOpenRouter->shouldReceive('supportsReasoning')->andReturn(false)->byDefault();
        $this->mockOpenRouter->shouldReceive('supportsStructuredOutput')->andReturn(false)->byDefault();
        $this->mockOpenRouter->shouldReceive('isMandatoryReasoning')->andReturn(false)->byDefault();

        $this->service = new IntentAnalysisService($this->mockOpenRouter);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_analyze_intent_returns_default_when_no_decision_model()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => null,
            'kb_enabled' => false,
        ]);

        $result = $this->service->analyzeIntent($bot, 'hello');

        $this->assertEquals('chat', $result['intent']);
        $this->assertEquals(1.0, $result['confidence']);
        $this->assertEquals('default', $result['method']);
        $this->assertTrue($result['skipped']);
    }

    public function test_analyze_intent_uses_llm_and_returns_knowledge_when_kb_enabled()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
            'kb_enabled' => true,
        ]);

        // Create flow with KB for the bot
        $flow = Flow::factory()->create([
            'bot_id' => $bot->id,
            'is_default' => true,
        ]);

        $kb = KnowledgeBase::create([
            'user_id' => $user->id,
            'name' => 'Test KB',
        ]);
        $flow->knowledgeBases()->attach($kb->id);

        $bot->default_flow_id = $flow->id;
        $bot->save();
        $bot->refresh();

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => '{"intent": "knowledge", "confidence": 0.9}',
                'model' => 'anthropic/claude-3-haiku',
            ]);

        $result = $this->service->analyzeIntent($bot, 'What is the price?');

        $this->assertEquals('knowledge', $result['intent']);
        $this->assertEquals(0.9, $result['confidence']);
        $this->assertEquals('llm_decision', $result['method']);
    }

    public function test_analyze_intent_calls_llm_when_decision_model_set()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
            'kb_enabled' => false,
        ]);

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => '{"intent": "chat", "confidence": 0.95}',
                'model' => 'anthropic/claude-3-haiku',
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 10,
                ],
            ]);

        $result = $this->service->analyzeIntent($bot, 'hello');

        $this->assertEquals('chat', $result['intent']);
        $this->assertEquals(0.95, $result['confidence']);
        $this->assertEquals('llm_decision', $result['method']);
        $this->assertEquals('anthropic/claude-3-haiku', $result['model_used']);
    }

    public function test_analyze_intent_with_knowledge_intent()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
            'kb_enabled' => true,
        ]);

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => '{"intent": "knowledge", "confidence": 0.9}',
                'model' => 'anthropic/claude-3-haiku',
            ]);

        $result = $this->service->analyzeIntent($bot, 'What is the price of product A?');

        $this->assertEquals('knowledge', $result['intent']);
        $this->assertEquals(0.9, $result['confidence']);
    }

    public function test_analyze_intent_handles_json_in_markdown()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
        ]);

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => "```json\n{\"intent\": \"chat\", \"confidence\": 0.85}\n```",
                'model' => 'anthropic/claude-3-haiku',
            ]);

        $result = $this->service->analyzeIntent($bot, 'hello');

        $this->assertEquals('chat', $result['intent']);
        $this->assertEquals(0.85, $result['confidence']);
    }

    public function test_analyze_intent_uses_text_fallback_on_invalid_json()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
        ]);

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => 'This is a knowledge question about data lookup',
                'model' => 'anthropic/claude-3-haiku',
            ]);

        $result = $this->service->analyzeIntent($bot, 'What is the price?', [
            'useFallback' => true,
        ]);

        // Should use fallback and detect 'knowledge' from keywords
        $this->assertEquals('knowledge', $result['intent']);
        $this->assertLessThanOrEqual(0.7, $result['confidence']);
    }

    public function test_analyze_intent_defaults_to_chat_on_error()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
            'kb_enabled' => false,
        ]);

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andThrow(new \Exception('API Error'));

        $result = $this->service->analyzeIntent($bot, 'hello');

        $this->assertEquals('chat', $result['intent']);
        $this->assertEquals(0, $result['confidence']);
        $this->assertEquals('error_fallback', $result['method']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_analyze_intent_validates_intent_values()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
        ]);

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => '{"intent": "invalid", "confidence": 0.9}',
                'model' => 'anthropic/claude-3-haiku',
            ]);

        $result = $this->service->analyzeIntent($bot, 'hello');

        // Invalid intent should default to 'chat'
        $this->assertEquals('chat', $result['intent']);
    }

    public function test_analyze_intent_clamps_confidence()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
        ]);

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => '{"intent": "chat", "confidence": 1.5}',
                'model' => 'anthropic/claude-3-haiku',
            ]);

        $result = $this->service->analyzeIntent($bot, 'hello');

        // Confidence should be clamped to 1.0
        $this->assertEquals(1.0, $result['confidence']);
    }

    public function test_analyze_intent_with_custom_valid_intents()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => 'anthropic/claude-3-haiku',
        ]);

        $this->mockOpenRouter->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => '{"intent": "flow", "confidence": 0.8}',
                'model' => 'anthropic/claude-3-haiku',
            ]);

        $result = $this->service->analyzeIntent($bot, 'trigger flow', [
            'validIntents' => ['chat', 'knowledge', 'flow'],
        ]);

        $this->assertEquals('flow', $result['intent']);
    }

    public function test_should_use_knowledge_base_returns_false_when_disabled()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'kb_enabled' => false,
        ]);

        $this->assertFalse($this->service->shouldUseKnowledgeBase($bot));
    }

    public function test_should_use_knowledge_base_returns_false_when_no_flow_kbs()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'kb_enabled' => true,
        ]);

        // No flow or KB attached - should return false
        $this->assertFalse($this->service->shouldUseKnowledgeBase($bot));
    }

    public function test_should_use_knowledge_base_returns_true_when_flow_has_kbs()
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'kb_enabled' => true,
        ]);

        $flow = Flow::factory()->create([
            'bot_id' => $bot->id,
            'is_default' => true,
        ]);

        $kb = KnowledgeBase::create([
            'user_id' => $user->id,
            'name' => 'Test KB',
        ]);
        $flow->knowledgeBases()->attach($kb->id);

        $bot->default_flow_id = $flow->id;
        $bot->save();

        // Reload bot to get the relationship
        $bot->refresh();

        $this->assertTrue($this->service->shouldUseKnowledgeBase($bot));
    }
}
