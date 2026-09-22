<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ExecuteFlowPlugins;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\FlowPluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ExecuteFlowPluginsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function records(): array
    {
        $bot = Bot::factory()->active()->line()->create();
        $conversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $message = Message::factory()->fromBot()->create(['conversation_id' => $conversation->id]);

        return [$bot, $conversation, $message];
    }

    public function test_job_configuration_is_best_effort_on_llm_queue(): void
    {
        $job = new ExecuteFlowPlugins(1, 2, 3);

        $this->assertSame(1, $job->tries);
        $this->assertSame(75, $job->timeout);
        $this->assertSame('llm', $job->queue);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($job->timeout, config('queue.connections.redis.retry_after'));
    }

    public function test_valid_records_execute_plugins_once(): void
    {
        [$bot, $conversation, $message] = $this->records();
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')->once()->withArgs(
            fn (Bot $actualBot, Conversation $actualConversation, Message $actualMessage) => $actualBot->is($bot) && $actualConversation->is($conversation) && $actualMessage->is($message)
        );

        (new ExecuteFlowPlugins($bot->id, $conversation->id, $message->id))->handle($plugins);

        $this->addToAssertionCount(1);
    }

    public function test_duplicate_delivery_for_the_same_message_executes_plugins_once(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();
        [$bot, $conversation, $message] = $this->records();
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')->once();
        $job = new ExecuteFlowPlugins($bot->id, $conversation->id, $message->id);

        $job->handle($plugins);
        $job->handle($plugins);

        $this->assertTrue(Cache::has("flow_plugins:message:{$message->id}"));
    }

    public function test_plugin_exception_is_swallowed(): void
    {
        [$bot, $conversation, $message] = $this->records();
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')->once()->andThrow(new \RuntimeException('secret details'));

        (new ExecuteFlowPlugins($bot->id, $conversation->id, $message->id))->handle($plugins);

        $this->addToAssertionCount(1);
    }

    public function test_snapshot_fields_are_forwarded_to_snapshot_entry_point(): void
    {
        [$bot, $conversation, $message] = $this->records();
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePluginsSnapshot')->once()->with(
            Mockery::on(fn (Bot $actualBot): bool => $actualBot->is($bot)),
            Mockery::on(fn (Conversation $actualConversation): bool => $actualConversation->is($conversation)),
            Mockery::on(fn (Message $actualMessage): bool => $actualMessage->is($message)),
            123,
            [4, 5, 6],
        );

        (new ExecuteFlowPlugins($bot->id, $conversation->id, $message->id, 123, [4, 5, 6]))->handle($plugins);

        $this->addToAssertionCount(1);
    }

    public function test_missing_record_is_a_noop(): void
    {
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldNotReceive('executePlugins');

        (new ExecuteFlowPlugins(999001, 999002, 999003))->handle($plugins);

        $this->assertTrue(true);
    }

    public function test_conversation_from_another_bot_is_rejected(): void
    {
        [$bot, $conversation, $message] = $this->records();
        $otherBot = Bot::factory()->active()->line()->create();
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldNotReceive('executePlugins');

        (new ExecuteFlowPlugins($otherBot->id, $conversation->id, $message->id))->handle($plugins);

        $this->assertNotSame($bot->id, $otherBot->id);
    }

    public function test_message_from_another_conversation_is_rejected(): void
    {
        [$bot, $conversation] = $this->records();
        $otherConversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $otherMessage = Message::factory()->fromBot()->create(['conversation_id' => $otherConversation->id]);
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldNotReceive('executePlugins');

        (new ExecuteFlowPlugins($bot->id, $conversation->id, $otherMessage->id))->handle($plugins);

        $this->assertNotSame($conversation->id, $otherConversation->id);
    }
}
