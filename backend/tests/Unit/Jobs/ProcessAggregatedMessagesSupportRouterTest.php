<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\MessageAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ProcessAggregatedMessagesSupportRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function context(): array
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $bot = Bot::factory()->active()->line()->create([
            'total_messages' => 0,
        ]);
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_test',
            'message_count' => 1,
            'unread_count' => 0,
            'is_handover' => false,
        ]);
        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'hello',
        ]);
        $aggregation = app(MessageAggregationService::class);
        $group = $aggregation->startOrContinueAggregation($conversation, $userMessage, 0);
        $this->assertNotNull($group);

        return [$bot, $conversation, $aggregation, $group['group_id']];
    }

    public function test_job_persists_support_router_decision_into_bot_message_metadata(): void
    {
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $decision = [
            'score' => 0.93,
            'handover' => false,
            'mode' => 'shadow',
            'model' => 'openai/gpt-6-luna-decisions',
            'latency_ms' => 421,
            'cost' => 0.0000639,
        ];
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andReturn([
            'content' => 'Bot reply',
            'model' => 'test/model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'cost' => 0.001,
            'rag_metadata' => [],
            'order_payload' => null,
            'support_router' => $decision,
        ]);

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once();

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, $ai, $delivery);

        $botMessage = Message::where('conversation_id', $conversation->id)
            ->where('sender', 'bot')
            ->sole();

        $this->assertIsArray($botMessage->metadata);
        $this->assertArrayHasKey('support_router', $botMessage->metadata);
        $this->assertSame($decision, $botMessage->metadata['support_router']);
    }

    public function test_job_without_support_router_key_stores_null_metadata_not_null_entry(): void
    {
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andReturn([
            'content' => 'Bot reply',
            'model' => 'test/model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'cost' => 0.001,
            'rag_metadata' => [],
            'order_payload' => null,
        ]);

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once();

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, $ai, $delivery);

        $botMessage = Message::where('conversation_id', $conversation->id)
            ->where('sender', 'bot')
            ->sole();

        // array_filter drops the null entry entirely instead of persisting
        // ['support_router' => null] noise for every router-off message.
        $this->assertTrue(
            $botMessage->metadata === null || ! array_key_exists('support_router', $botMessage->metadata ?? [])
        );
    }
}
