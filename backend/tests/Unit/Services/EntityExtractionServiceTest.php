<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\EntityExtractionService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntityExtractionService $service;

    private OpenRouterService $openRouter;

    private User $user;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openRouter = $this->createMock(OpenRouterService::class);
        $this->service = new EntityExtractionService($this->openRouter);

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_extract_returns_empty_when_no_messages(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
        ]);

        $result = $this->service->extractAndSave($conversation);

        $this->assertEmpty($result['extracted']);
        $this->assertEquals(0, $result['saved_count']);
    }

    public function test_extract_entities_from_messages(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'สวัสดีครับ ผมชื่อสมชาย สนใจสินค้าตัว A ครับ',
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'สวัสดีครับคุณสมชาย สินค้า A มีราคา 500 บาทครับ',
        ]);

        $this->openRouter->method('chat')
            ->willReturn([
                'content' => '{"entities": [{"type": "name", "value": "สมชาย"}, {"type": "product_interest", "value": "สินค้า A"}]}',
            ]);

        $result = $this->service->extractAndSave($conversation);

        $this->assertCount(2, $result['extracted']);
        $this->assertEquals(2, $result['saved_count']);

        // Verify memory_notes were saved
        $conversation->refresh();
        $notes = collect($conversation->memory_notes)->where('type', 'memory');
        $this->assertCount(2, $notes);
    }

    public function test_extract_skips_duplicate_entities(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [
                [
                    'type' => 'memory',
                    'content' => 'ชื่อลูกค้า: สมชาย',
                    'extracted_at' => now()->toISOString(),
                    'source' => 'auto_entity_extraction',
                ],
            ],
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'ผมชื่อสมชาย สนใจสินค้า B ครับ',
        ]);

        $this->openRouter->method('chat')
            ->willReturn([
                'content' => '{"entities": [{"type": "name", "value": "สมชาย"}, {"type": "product_interest", "value": "สินค้า B"}]}',
            ]);

        $result = $this->service->extractAndSave($conversation);

        // Name should be skipped (duplicate), only product_interest saved
        $this->assertEquals(1, $result['saved_count']);

        $conversation->refresh();
        $notes = collect($conversation->memory_notes)->where('type', 'memory');
        $this->assertCount(2, $notes); // 1 existing + 1 new
    }

    public function test_extract_handles_llm_failure_gracefully(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'ทดสอบ',
        ]);

        $this->openRouter->method('chat')
            ->willThrowException(new \RuntimeException('API error'));

        $result = $this->service->extractAndSave($conversation);

        $this->assertEmpty($result['extracted']);
        $this->assertEquals(0, $result['saved_count']);
    }

    public function test_extract_handles_invalid_json_gracefully(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'ทดสอบ',
        ]);

        $this->openRouter->method('chat')
            ->willReturn(['content' => 'not valid json']);

        $result = $this->service->extractAndSave($conversation);

        $this->assertEmpty($result['extracted']);
        $this->assertEquals(0, $result['saved_count']);
    }

    public function test_extract_filters_invalid_entity_types(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'ทดสอบ',
        ]);

        $this->openRouter->method('chat')
            ->willReturn([
                'content' => '{"entities": [{"type": "name", "value": "สมชาย"}, {"type": "invalid_type", "value": "test"}, {"type": "phone", "value": ""}]}',
            ]);

        $result = $this->service->extractAndSave($conversation);

        // Only "name" should be saved (invalid_type filtered, empty phone filtered)
        $this->assertEquals(1, $result['saved_count']);
    }
}
