<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TagServiceTest extends TestCase
{
    use RefreshDatabase;

    private TagService $service;
    private User $user;
    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TagService();
        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * @group postgres
     */
    public function test_get_all_tags_returns_unique_tags(): void
    {
        // Skip on SQLite - uses PostgreSQL-specific jsonb functions
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }

        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => ['vip', 'urgent'],
        ]);
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => ['vip', 'follow-up'],
        ]);

        $result = $this->service->getAllTags($this->bot);

        $this->assertCount(3, $result);
        $this->assertContains('vip', $result);
        $this->assertContains('urgent', $result);
        $this->assertContains('follow-up', $result);
    }

    /**
     * @group postgres
     */
    public function test_get_all_tags_returns_sorted_tags(): void
    {
        // Skip on SQLite - uses PostgreSQL-specific jsonb functions
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }

        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => ['zebra', 'apple', 'mango'],
        ]);

        $result = $this->service->getAllTags($this->bot);

        $this->assertEquals(['apple', 'mango', 'zebra'], $result);
    }

    /**
     * @group postgres
     */
    public function test_get_all_tags_returns_empty_when_no_tags(): void
    {
        // Skip on SQLite - uses PostgreSQL-specific jsonb functions
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }

        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => null,
        ]);

        $result = $this->service->getAllTags($this->bot);

        $this->assertEmpty($result);
    }

    public function test_add_tags_merges_with_existing(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => ['existing'],
        ]);

        $result = $this->service->addTags($conversation, ['new-tag', 'another']);

        $this->assertCount(3, $result);
        $this->assertContains('existing', $result);
        $this->assertContains('new-tag', $result);
        $this->assertContains('another', $result);
    }

    public function test_add_tags_handles_duplicates(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => ['existing'],
        ]);

        $result = $this->service->addTags($conversation, ['existing', 'new-tag']);

        $this->assertCount(2, $result);
    }

    public function test_add_tags_invalidates_cache(): void
    {
        Cache::shouldReceive('forget')
            ->once()
            ->with("bot:{$this->bot->id}:conversation:tags");

        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => [],
        ]);

        $this->service->addTags($conversation, ['new-tag']);
    }

    public function test_remove_tag_removes_single_tag(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => ['keep', 'remove', 'also-keep'],
        ]);

        $result = $this->service->removeTag($conversation, 'remove');

        $this->assertCount(2, $result);
        $this->assertContains('keep', $result);
        $this->assertContains('also-keep', $result);
        $this->assertNotContains('remove', $result);
    }

    public function test_remove_tag_throws_exception_when_not_found(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'tags' => ['existing'],
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->removeTag($conversation, 'non-existent');
    }

    public function test_bulk_add_tags_updates_multiple_conversations(): void
    {
        $conversations = Conversation::factory()->count(3)->create([
            'bot_id' => $this->bot->id,
            'tags' => [],
        ]);

        $ids = $conversations->pluck('id')->toArray();
        $count = $this->service->bulkAddTags($this->bot, $ids, ['bulk-tag']);

        $this->assertEquals(3, $count);

        foreach ($conversations as $conversation) {
            $conversation->refresh();
            $this->assertContains('bulk-tag', $conversation->tags);
        }
    }

    public function test_bulk_add_tags_throws_exception_for_invalid_conversations(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
        ]);

        // Create conversation belonging to different bot
        $otherBot = Bot::factory()->create(['user_id' => $this->user->id]);
        $otherConversation = Conversation::factory()->create([
            'bot_id' => $otherBot->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Some conversations do not belong to this bot');

        $this->service->bulkAddTags($this->bot, [
            $conversation->id,
            $otherConversation->id,
        ], ['tag']);
    }
}
