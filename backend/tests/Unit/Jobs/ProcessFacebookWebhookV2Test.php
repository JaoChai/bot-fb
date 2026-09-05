<?php

namespace Tests\Unit\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Jobs\ProcessFacebookWebhook;
use App\Models\Bot;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ProcessFacebookWebhookV2Test extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->bot = Bot::factory()->create([
            'user_id' => $user->id,
            'channel_type' => 'facebook',
            'status' => 'active',
            'channel_access_token' => 'tok',
        ]);
        config(['webhook_pipeline_v2.enabled' => true, 'webhook_pipeline_v2.bot_ids' => [(string) $this->bot->id]]);
        Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'PSID-1', 'message_id' => 'mid.out'], 200)]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
    }

    public function test_text_message_saves_user_and_bot_messages_sends_reply_and_broadcasts(): void
    {
        $payload = include base_path('tests/fixtures/facebook-text-message.php');

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')->once()->andReturnUsing(function ($bot, $conversation, $userMessage) {
            return $conversation->messages()->create(['sender' => 'bot', 'content' => 'สวัสดีค่ะ', 'type' => 'text']);
        });

        (new ProcessFacebookWebhook($this->bot, $payload))->handle($aiService);

        $this->assertSame(1, Message::where('sender', 'user')->count());
        $this->assertSame(1, Message::where('sender', 'bot')->count());
        $conversation = Message::where('sender', 'user')->first()->conversation;
        $this->assertSame(2, (int) $conversation->message_count);
        $this->assertSame(1, (int) $conversation->unread_count);

        // Reply was sent through the Graph API (FacebookChannelAdapter → FacebookService::sendMessage)
        Http::assertSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com') && ($req['message']['text'] ?? null) === 'สวัสดีค่ะ');

        Event::assertDispatched(MessageSent::class, 2);
        Event::assertDispatched(ConversationUpdated::class, fn ($e) => $e->updateType === 'created');
    }

    public function test_duplicate_mid_is_ignored_end_to_end(): void
    {
        $payload = include base_path('tests/fixtures/facebook-text-message.php');
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')->once()->andReturnUsing(
            fn ($bot, $conversation) => $conversation->messages()->create(['sender' => 'bot', 'content' => 'x', 'type' => 'text'])
        );

        (new ProcessFacebookWebhook($this->bot, $payload))->handle($aiService);
        (new ProcessFacebookWebhook($this->bot, $payload))->handle($aiService); // same mid

        $this->assertSame(1, Message::where('sender', 'user')->count());
    }
}
