<?php

namespace Tests\Unit\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Exceptions\OpenRouterException;
use App\Jobs\ExecuteFlowPlugins;
use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\FlowPluginService;
use App\Services\MessageAggregationService;
use App\Services\RedisHealthGate;
use App\Support\QueueRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessAggregatedMessagesPluginDispatchTest extends TestCase
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

    private function successfulAi(string $content = 'Bot reply'): AIService
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andReturn([
            'content' => $content,
            'model' => 'test/model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'cost' => 0.001,
            'rag_metadata' => [],
            'order_payload' => null,
        ]);

        return $ai;
    }

    public function test_successful_response_dispatches_plugins_and_preserves_side_effects(): void
    {
        Queue::fake([ExecuteFlowPlugins::class]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->withArgs(
            fn (Bot $actualBot, Conversation $actualConversation, Message $message, string $externalUserId) => $actualBot->is($bot)
                && $actualConversation->is($conversation)
                && $message->content === 'Bot reply'
                && $externalUserId === 'U_test'
        );

        $job = new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test');
        $job->handle($aggregation, $this->successfulAi(), $delivery);

        $botMessage = Message::where('conversation_id', $conversation->id)
            ->where('sender', 'bot')
            ->sole();
        Queue::assertPushed(ExecuteFlowPlugins::class, fn (ExecuteFlowPlugins $queued) => $queued->botId === $bot->id
            && $queued->conversationId === $conversation->id
            && $queued->messageId === $botMessage->id
            && $queued->queue === QueueRouter::QUEUE_LLM
        );
        $this->assertSame(2, $conversation->fresh()->message_count);
        $this->assertSame(1, $conversation->fresh()->unread_count);
        $this->assertSame(1, $bot->fresh()->total_messages);
        Event::assertDispatched(MessageSent::class);
        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_friendly_fallback_also_dispatches_plugins(): void
    {
        Queue::fake([ExecuteFlowPlugins::class]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $failure = new OpenRouterException('upstream failed', 503);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andThrow($failure);
        $ai->shouldReceive('getErrorMessage')->once()->with($failure)->andReturn('ขออภัย ระบบขัดข้อง');

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->withArgs(
            fn (Bot $actualBot, Conversation $actualConversation, Message $message, string $externalUserId) => $message->content === 'ขออภัย ระบบขัดข้อง' && $externalUserId === 'U_test'
        );

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, $ai, $delivery);

        Queue::assertPushed(ExecuteFlowPlugins::class, fn (ExecuteFlowPlugins $queued) => Message::find($queued->messageId)?->content === 'ขออภัย ระบบขัดข้อง'
        );
    }

    public function test_failed_delivery_does_not_dispatch_plugins(): void
    {
        Queue::fake([ExecuteFlowPlugins::class]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->andThrow(new \RuntimeException('LINE unavailable'));

        try {
            (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
                ->handle($aggregation, $this->successfulAi(), $delivery);
            $this->fail('Expected delivery failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('LINE unavailable', $e->getMessage());
        }

        Queue::assertNotPushed(ExecuteFlowPlugins::class);
    }

    public function test_plugin_execution_can_acquire_lock_after_parent_releases_it(): void
    {
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
        Queue::fake([ExecuteFlowPlugins::class]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $redisGate = Mockery::mock(RedisHealthGate::class);
        $redisGate->shouldReceive('isRedisUp')->andReturn(true);
        $this->app->instance(RedisHealthGate::class, $redisGate);

        $lockWasFree = false;
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePluginsSnapshot')->once()->andReturnUsing(
            function () use ($conversation, &$lockWasFree): void {
                $probe = Cache::lock("ai_response:{$conversation->id}", 30);
                $lockWasFree = $probe->get();
                if ($lockWasFree) {
                    $probe->release();
                }
            }
        );
        $this->app->instance(FlowPluginService::class, $plugins);

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once();

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, $this->successfulAi(), $delivery);

        $this->assertFalse($lockWasFree, 'plugin execution must not run inline on the sync queue');
        $queued = Queue::pushed(ExecuteFlowPlugins::class)->sole();
        $queued->handle($plugins);
        $this->assertTrue($lockWasFree, 'response lock must be released before plugin execution');
    }

    public function test_statistics_failure_still_dispatches_plugins_and_propagates_original_exception(): void
    {
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();
        $statsFailure = new \RuntimeException('statistics failure');
        $dispatched = false;

        $job = Mockery::mock(ProcessAggregatedMessages::class, [$bot, $conversation, $groupId, 'U_test'])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('updateStats')->once()->andThrow($statsFailure);
        $job->shouldReceive('dispatchFlowPlugins')->once()->andReturnUsing(
            function () use (&$dispatched): void {
                $dispatched = true;
            }
        );

        try {
            $job->handle($aggregation, $this->successfulAi(), $this->deliveryMock());
            $this->fail('Expected statistics failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('statistics failure', $e->getMessage());
        }

        $this->assertTrue($dispatched);
    }

    public function test_dispatch_failure_does_not_replace_successful_response(): void
    {
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $job = Mockery::mock(ProcessAggregatedMessages::class, [$bot, $conversation, $groupId, 'U_test'])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('dispatchFlowPlugins')->once()->andThrow(new \RuntimeException('queue unavailable'));

        $job->handle($aggregation, $this->successfulAi(), $this->deliveryMock());

        $this->assertSame(2, $conversation->fresh()->message_count);
        $this->assertSame(1, $conversation->fresh()->unread_count);
    }

    public function test_snapshot_is_immutable_after_dispatch(): void
    {
        Queue::fake([ExecuteFlowPlugins::class]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();
        $firstFlow = Flow::factory()->create(['bot_id' => $bot->id]);
        $secondFlow = Flow::factory()->create(['bot_id' => $bot->id]);
        $conversation->update(['current_flow_id' => $firstFlow->id]);

        $oldMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'captured context',
        ]);

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, $this->successfulAi(), $this->deliveryMock());

        $queued = Queue::pushed(ExecuteFlowPlugins::class)->sole();
        $botMessage = Message::query()->where('conversation_id', $conversation->id)->where('sender', 'bot')->sole();
        $conversation->update(['current_flow_id' => $secondFlow->id]);
        $newMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'newer context',
        ]);

        $this->assertSame($firstFlow->id, $queued->flowId);
        $this->assertContains($oldMessage->id, $queued->contextMessageIds);
        $this->assertContains($botMessage->id, $queued->contextMessageIds);
        $this->assertNotContains($newMessage->id, $queued->contextMessageIds);
    }

    private function deliveryMock(): AggregatedMessageDeliveryService
    {
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once();

        return $delivery;
    }
}
