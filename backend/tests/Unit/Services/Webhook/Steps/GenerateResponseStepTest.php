<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Webhook\Steps\GenerateResponseStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * GenerateResponseStep is a thin delegation to AIService
 * (generateAndSaveResponse) — the same AI path the three jobs use today.
 */
class GenerateResponseStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_delegates_to_ai_service_and_stores_bot_message(): void
    {
        $bot = Bot::factory()->active()->create();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'hello',
            'type' => 'text',
        ]);

        $expectedBotMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'hi there',
            'type' => 'text',
        ]);

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')
            ->once()
            ->with($bot, $conversation, $userMessage)
            ->andReturn($expectedBotMessage);
        $this->app->instance(AIService::class, $aiService);

        $ctx = new WebhookContext($bot, [], 'line');
        $ctx->conversation = $conversation;
        $ctx->userMessage = $userMessage;

        $step = new GenerateResponseStep();
        $step->handle($ctx, fn () => null);

        $this->assertSame($expectedBotMessage->id, $ctx->metadata['bot_message_id']);
    }

    public function test_skips_ai_service_when_no_user_message(): void
    {
        $bot = Bot::factory()->active()->create();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')->never();
        $this->app->instance(AIService::class, $aiService);

        $ctx = new WebhookContext($bot, [], 'line');
        $ctx->conversation = $conversation;
        $ctx->userMessage = null;

        $step = new GenerateResponseStep();
        $step->handle($ctx, fn () => null);

        $this->assertArrayNotHasKey('bot_message_id', $ctx->metadata);
    }
}
