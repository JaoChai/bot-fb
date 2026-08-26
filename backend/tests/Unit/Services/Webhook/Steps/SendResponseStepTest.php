<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Channel\ChannelAdapterFactory;
use App\Services\Channel\ChannelAdapterInterface;
use App\Services\Channel\LINEChannelAdapter;
use App\Services\Channel\TelegramChannelAdapter;
use App\Services\LINEService;
use App\Services\TelegramService;
use App\Services\Webhook\Steps\SendResponseStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * SendResponseStep sends the generated bot message through the
 * ChannelAdapterInterface for the context's channel (resolved via
 * ChannelAdapterFactory). The step's delegation is proven by registering a
 * concrete adapter mock under the channel type and asserting sendMessage is
 * called with the bot, conversation, type, and content.
 */
class SendResponseStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build a real factory (so `make` for the unregistered channel type would
     * throw), then register a concrete adapter mock under the given type so
     * the step's delegation to the adapter is directly observable.
     */
    private function factoryWithAdapter(ChannelAdapterInterface $adapter, string $channelType): ChannelAdapterFactory
    {
        $lineService = Mockery::mock(LINEService::class);
        $telegramService = Mockery::mock(TelegramService::class);
        $factory = new ChannelAdapterFactory(
            new LINEChannelAdapter($lineService),
            new TelegramChannelAdapter($telegramService)
        );
        $factory->register($channelType, $adapter);

        return $factory;
    }

    public function test_sends_bot_message_via_channel_adapter(): void
    {
        $bot = Bot::factory()->active()->line()->create();
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_send',
        ]);

        $adapter = Mockery::mock(ChannelAdapterInterface::class);
        $adapter->shouldReceive('getChannelType')->andReturn('line');
        $sent = [];
        $adapter->shouldReceive('sendMessage')
            ->once()
            ->with($bot, $conversation, 'text', 'hello from bot', null)
            ->andReturnUsing(function (Bot $b, Conversation $c, string $type, string $content, ?string $mediaUrl) use (&$sent) {
                $sent[] = [$b->id, $c->id, $type, $content, $mediaUrl];
            });

        $step = new SendResponseStep($this->factoryWithAdapter($adapter, 'line'));

        $ctx = new WebhookContext($bot, [], 'line');
        $ctx->conversation = $conversation;
        $ctx->metadata['bot_message'] = [
            'content' => 'hello from bot',
            'type' => 'text',
            'media_url' => null,
        ];

        $step->handle($ctx, fn () => null);

        // The adapter's sendMessage was called exactly once with the right args.
        $this->assertCount(1, $sent);
        $this->assertSame($bot->id, $sent[0][0]);
        $this->assertSame($conversation->id, $sent[0][1]);
        $this->assertSame('text', $sent[0][2]);
        $this->assertSame('hello from bot', $sent[0][3]);
        $this->assertNull($sent[0][4]);
    }

    public function test_does_nothing_when_no_bot_message(): void
    {
        $bot = Bot::factory()->active()->line()->create();
        $conversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);

        $adapter = Mockery::mock(ChannelAdapterInterface::class);
        $adapter->shouldReceive('getChannelType')->andReturn('line');
        $adapter->shouldReceive('sendMessage')->never();

        $step = new SendResponseStep($this->factoryWithAdapter($adapter, 'line'));

        $ctx = new WebhookContext($bot, [], 'line');
        $ctx->conversation = $conversation;
        $ctx->metadata['bot_message'] = null;

        $step->handle($ctx, fn () => null);

        // No bot message → no send; the ctx metadata is left untouched.
        $this->assertNull($ctx->metadata['bot_message']);
    }
}
