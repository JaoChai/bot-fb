---
id: feature-004-database-testing
title: Test Database State Changes
impact: MEDIUM
impactDescription: "Data not persisted or persisted incorrectly"
category: feature
tags: [feature-test, database, state, assertions]
relatedRules: [unit-004-factory-usage, feature-001-auth-testing]
---

## Why This Matters

Feature tests should verify that data is correctly persisted. Just checking response status doesn't guarantee the database was updated correctly.

## Bad Example

```php
public function test_creates_bot(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/bots', [
            'name' => 'Test Bot',
            'platform' => 'line',
        ]);

    // Only checks response, not database
    $response->assertCreated();
}

public function test_deletes_bot(): void
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/bots/{$bot->id}");

    // Doesn't verify deletion
    $response->assertNoContent();
}
```

**Why it's problematic:**
- Data might not be saved
- Wrong fields might be saved
- Relationships might break
- Soft deletes might not work

## Good Example

```php
class BotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_bot_in_database(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => 'Test Bot',
                'platform' => 'line',
            ]);

        $response->assertCreated();

        // Verify database state
        $this->assertDatabaseHas('bots', [
            'name' => 'Test Bot',
            'platform' => 'line',
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        // Verify count
        $this->assertEquals(1, Bot::count());
    }

    public function test_updates_bot_in_database(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/bots/{$bot->id}", [
                'name' => 'New Name',
            ]);

        $response->assertOk();

        // Verify update
        $this->assertDatabaseHas('bots', [
            'id' => $bot->id,
            'name' => 'New Name',
        ]);

        // Verify old value gone
        $this->assertDatabaseMissing('bots', [
            'id' => $bot->id,
            'name' => 'Old Name',
        ]);

        // Verify using fresh model
        $this->assertEquals('New Name', $bot->fresh()->name);
    }

    public function test_soft_deletes_bot(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->deleteJson("/api/v1/bots/{$bot->id}");

        $response->assertNoContent();

        // Verify soft deleted (still in DB but with deleted_at)
        $this->assertSoftDeleted('bots', ['id' => $bot->id]);

        // Verify not in normal queries
        $this->assertDatabaseMissing('bots', [
            'id' => $bot->id,
            'deleted_at' => null,
        ]);

        // Verify can be found with trashed
        $this->assertNotNull(Bot::withTrashed()->find($bot->id));
    }

    public function test_creates_related_records(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/bots/{$bot->id}/knowledge-bases", [
                'name' => 'Test KB',
            ]);

        $response->assertCreated();

        // Verify relationship
        $this->assertDatabaseHas('knowledge_bases', [
            'bot_id' => $bot->id,
            'name' => 'Test KB',
        ]);

        // Verify count on relationship
        $this->assertEquals(1, $bot->fresh()->knowledgeBases()->count());
    }

    public function test_cascades_delete_to_related(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()
            ->has(Conversation::factory()->count(3))
            ->create(['user_id' => $user->id]);

        $conversationIds = $bot->conversations->pluck('id')->toArray();

        $this->actingAs($user)
            ->deleteJson("/api/v1/bots/{$bot->id}");

        // Verify related records deleted
        foreach ($conversationIds as $id) {
            $this->assertSoftDeleted('conversations', ['id' => $id]);
        }
    }
}
```

**Why it's better:**
- Verifies actual persistence
- Checks relationships
- Tests soft deletes
- Tests cascading

## Test Coverage

| Operation | Database Checks |
|-----------|-----------------|
| Create | assertDatabaseHas, count |
| Update | assertDatabaseHas, assertDatabaseMissing |
| Delete | assertSoftDeleted, assertDatabaseMissing |
| Relations | Relationship count, cascade |

## Run Command

```bash
# Run with database transaction rollback for speed
php artisan test --filter Feature --parallel
```

## Project-Specific Notes

**BotFacebook Database Testing:**

```php
// Test message persistence
class ConversationControllerTest extends TestCase
{
    public function test_stores_user_message_and_response(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        $this->mockLLMService();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Hello',
            ]);

        $response->assertCreated();

        // User message saved
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Hello',
            'role' => 'user',
        ]);

        // Assistant response saved
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
        ]);

        // Message count increased
        $this->assertEquals(2, $conversation->fresh()->messages()->count());
    }
}
```
