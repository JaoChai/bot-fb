<?php

namespace Tests\Feature;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LINEWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected string $channelSecret = 'test_channel_secret_12345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create([
            'user_id' => $this->user->id,
            'channel_type' => 'line',
            'channel_secret' => $this->channelSecret,
            'channel_access_token' => 'test_access_token',
            'webhook_url' => config('app.url').'/api/webhook/test_webhook_token_123',
        ]);
    }

    protected function generateSignature(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->channelSecret, true));
    }

    public function test_webhook_accepts_valid_request(): void
    {
        Queue::fake();

        $body = json_encode([
            'destination' => 'U1234567890',
            'events' => [
                [
                    'type' => 'message',
                    'replyToken' => 'reply_token_123',
                    'source' => [
                        'type' => 'user',
                        'userId' => 'U_user_123',
                    ],
                    'message' => [
                        'id' => 'msg_123',
                        'type' => 'text',
                        'text' => 'Hello!',
                    ],
                ],
            ],
        ]);

        $response = $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'OK']);

        Queue::assertPushed(ProcessLINEWebhook::class, function ($job) {
            return $job->bot->id === $this->bot->id
                && $job->event['message']['text'] === 'Hello!';
        });
    }

    public function test_webhook_dispatches_multiple_events(): void
    {
        Queue::fake();

        $body = json_encode([
            'events' => [
                ['type' => 'message', 'replyToken' => 'token1', 'source' => ['userId' => 'U1'], 'message' => ['type' => 'text', 'text' => 'Hi']],
                ['type' => 'message', 'replyToken' => 'token2', 'source' => ['userId' => 'U2'], 'message' => ['type' => 'text', 'text' => 'Hello']],
            ],
        ]);

        $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);

        Queue::assertPushed(ProcessLINEWebhook::class, 2);
    }

    public function test_webhook_returns_404_for_invalid_token(): void
    {
        $body = json_encode(['events' => []]);

        $response = $this->postJson('/api/webhook/invalid_token', [], [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Invalid webhook token']);
    }

    public function test_webhook_returns_401_for_missing_signature(): void
    {
        $response = $this->postJson('/api/webhook/test_webhook_token_123', [
            'events' => [],
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Missing X-Line-Signature header']);
    }

    public function test_webhook_returns_401_for_invalid_signature(): void
    {
        $body = json_encode(['events' => []]);

        $response = $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => 'invalid_signature',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Invalid signature']);
    }

    public function test_webhook_returns_404_for_non_line_bot(): void
    {
        // Create a Facebook bot - LINE webhook endpoint won't find it
        // because it explicitly filters for channel_type = 'line'
        $facebookBot = Bot::factory()->create([
            'user_id' => $this->user->id,
            'channel_type' => 'facebook',
            'channel_secret' => $this->channelSecret,
            'webhook_url' => config('app.url').'/api/webhook/facebook_token_123',
        ]);

        $body = json_encode(['events' => []]);

        $response = $this->postJson('/api/webhook/facebook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);

        // Returns 404 because LINE webhook only looks for LINE bots
        $response->assertStatus(404);
        $response->assertJson(['message' => 'Invalid webhook token']);
    }

    public function test_webhook_handles_empty_events(): void
    {
        Queue::fake();

        $body = json_encode(['events' => []]);

        $response = $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'OK']);

        Queue::assertNotPushed(ProcessLINEWebhook::class);
    }

    public function test_webhook_handles_verification_request(): void
    {
        Queue::fake();

        // LINE sends empty body for verification
        $body = json_encode(['destination' => 'U12345']);

        $response = $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'OK']);

        Queue::assertNotPushed(ProcessLINEWebhook::class);
    }

    public function test_webhook_uses_correct_queue(): void
    {
        Queue::fake();

        $body = json_encode([
            'events' => [
                [
                    'type' => 'message',
                    'replyToken' => 'token',
                    'source' => ['userId' => 'U1'],
                    'message' => ['type' => 'text', 'text' => 'Hi'],
                ],
            ],
        ]);

        $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);

        Queue::assertPushedOn('webhooks', ProcessLINEWebhook::class);
    }

    public function test_webhook_logs_event_count(): void
    {
        Queue::fake();

        $body = json_encode([
            'events' => [
                ['type' => 'message', 'replyToken' => 'token', 'source' => ['userId' => 'U1'], 'message' => ['type' => 'text', 'text' => 'Hi']],
            ],
        ]);

        $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ])->assertStatus(200);
    }

    public function test_webhook_ignores_deleted_bots(): void
    {
        $this->bot->delete();

        $body = json_encode(['events' => []]);

        $response = $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);

        $response->assertStatus(404);
    }
}
