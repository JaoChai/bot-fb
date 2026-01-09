<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private NoteService $service;
    private User $user;
    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NoteService();
        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_get_notes_returns_sorted_notes(): void
    {
        $notes = [
            [
                'id' => 'note-1',
                'content' => 'First note',
                'type' => 'note',
                'created_by' => $this->user->id,
                'created_at' => now()->subHour()->toISOString(),
                'updated_at' => now()->subHour()->toISOString(),
            ],
            [
                'id' => 'note-2',
                'content' => 'Second note',
                'type' => 'note',
                'created_by' => $this->user->id,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ],
        ];

        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => $notes,
        ]);

        $result = $this->service->getNotes($conversation);

        $this->assertCount(2, $result);
        // Should be sorted by created_at descending (newest first)
        $this->assertEquals('note-2', $result[0]['id']);
        $this->assertEquals('note-1', $result[1]['id']);
    }

    public function test_get_notes_returns_empty_array_when_no_notes(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => null,
        ]);

        $result = $this->service->getNotes($conversation);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_add_note_creates_new_note(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);

        $result = $this->service->addNote($conversation, [
            'content' => 'Test note content',
            'type' => 'memory',
        ], $this->user->id);

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('Test note content', $result['content']);
        $this->assertEquals('memory', $result['type']);
        $this->assertEquals($this->user->id, $result['created_by']);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('updated_at', $result);

        // Verify it's persisted
        $conversation->refresh();
        $this->assertCount(1, $conversation->memory_notes);
    }

    public function test_add_note_uses_default_type(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);

        $result = $this->service->addNote($conversation, [
            'content' => 'Test note',
        ], $this->user->id);

        $this->assertEquals('note', $result['type']);
    }

    public function test_update_note_modifies_existing_note(): void
    {
        $noteId = 'note-to-update';
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [
                [
                    'id' => $noteId,
                    'content' => 'Original content',
                    'type' => 'note',
                    'created_by' => $this->user->id,
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ],
            ],
        ]);

        $result = $this->service->updateNote($conversation, $noteId, [
            'content' => 'Updated content',
            'type' => 'memory',
        ]);

        $this->assertEquals('Updated content', $result['content']);
        $this->assertEquals('memory', $result['type']);
    }

    public function test_update_note_throws_exception_when_not_found(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->updateNote($conversation, 'non-existent-id', [
            'content' => 'Updated content',
        ]);
    }

    public function test_delete_note_removes_note(): void
    {
        $noteId = 'note-to-delete';
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [
                [
                    'id' => $noteId,
                    'content' => 'To be deleted',
                    'type' => 'note',
                    'created_by' => $this->user->id,
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ],
                [
                    'id' => 'other-note',
                    'content' => 'Keep this',
                    'type' => 'note',
                    'created_by' => $this->user->id,
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ],
            ],
        ]);

        $this->service->deleteNote($conversation, $noteId);

        $conversation->refresh();
        $this->assertCount(1, $conversation->memory_notes);
        $this->assertEquals('other-note', $conversation->memory_notes[0]['id']);
    }

    public function test_delete_note_throws_exception_when_not_found(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->deleteNote($conversation, 'non-existent-id');
    }
}
