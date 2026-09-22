<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ExecuteFlowPlugins;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\FlowPluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(90, $job->timeout);
        $this->assertSame('llm', $job->queue);
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
