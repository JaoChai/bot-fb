<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\IdempotencyService;
use App\Services\Chat\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IdempotencyService;
    }

    public function test_first_request_returns_null(): void
    {
        $result = $this->service->check('test-uuid-1', '/api/test', ['content' => 'hello']);
        $this->assertNull($result);
    }

    public function test_stores_and_retrieves_response(): void
    {
        $key = 'test-uuid-2';
        $endpoint = '/api/test';
        $body = ['content' => 'hello'];
        $response = ['id' => 1, 'content' => 'hello'];

        $this->service->store($key, $endpoint, $body, $response);
        $cached = $this->service->check($key, $endpoint, $body);

        $this->assertNotNull($cached);
        $this->assertEquals($response, $cached);
    }

    public function test_same_key_different_body_returns_conflict(): void
    {
        $key = 'test-uuid-3';
        $endpoint = '/api/test';

        $this->service->store($key, $endpoint, ['content' => 'hello'], ['id' => 1]);

        $this->expectException(\App\Exceptions\IdempotencyConflictException::class);
        $this->service->check($key, $endpoint, ['content' => 'different']);
    }

    public function test_agent_message_idempotency_returns_cached_response(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $message = Message::factory()->fromAgent()->create(['conversation_id' => $conversation->id]);

        $mockMessageService = Mockery::mock(MessageService::class);
        $mockMessageService->shouldReceive('sendAgentMessage')
            ->once()
            ->andReturn(['message' => $message, 'delivery_error' => null]);
        $this->app->instance(MessageService::class, $mockMessageService);

        $idempotencyKey = 'test-idempotency-key-123';
        $payload = ['content' => 'Hello from agent'];

        // First request should succeed (201)
        $response1 = $this->actingAs($user)
            ->postJson(
                "/api/bots/{$bot->id}/conversations/{$conversation->id}/agent-message",
                $payload,
                ['Idempotency-Key' => $idempotencyKey]
            );
        $response1->assertStatus(201);

        // Second request with same key should return cached (200)
        $response2 = $this->actingAs($user)
            ->postJson(
                "/api/bots/{$bot->id}/conversations/{$conversation->id}/agent-message",
                $payload,
                ['Idempotency-Key' => $idempotencyKey]
            );
        $response2->assertOk();
    }
}
