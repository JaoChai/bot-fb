---
id: feature-006-edge-cases
title: Test Edge Cases and Boundaries
impact: MEDIUM
impactDescription: "Application crashes or behaves unexpectedly at boundaries"
category: feature
tags: [feature-test, edge-cases, boundaries, robustness]
relatedRules: [feature-002-validation-testing, unit-003-assertion-quality]
---

## Why This Matters

Edge cases are where bugs hide. Empty arrays, null values, maximum lengths, and boundary conditions need explicit tests.

## Bad Example

```php
class MessageControllerTest extends TestCase
{
    public function test_sends_message(): void
    {
        // Only tests normal case
        $response = $this->actingAs($user)
            ->postJson('/api/v1/messages', [
                'content' => 'Hello world',
            ]);

        $response->assertCreated();
    }
}

// Missing: empty string, very long string, special characters, etc.
```

**Why it's problematic:**
- Empty inputs not tested
- Max limits not tested
- Special characters not tested
- Zero/null values not tested

## Good Example

```php
class MessageControllerTest extends TestCase
{
    use RefreshDatabase;

    // Empty content
    public function test_rejects_empty_message(): void
    {
        $conversation = $this->createConversation();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => '',
            ]);

        $response->assertUnprocessable();
    }

    public function test_rejects_whitespace_only_message(): void
    {
        $conversation = $this->createConversation();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => '   ',
            ]);

        $response->assertUnprocessable();
    }

    // Maximum length
    public function test_accepts_message_at_max_length(): void
    {
        $conversation = $this->createConversation();
        $maxContent = str_repeat('a', 10000);  // At limit

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => $maxContent,
            ]);

        $response->assertCreated();
    }

    public function test_rejects_message_over_max_length(): void
    {
        $conversation = $this->createConversation();
        $overMaxContent = str_repeat('a', 10001);  // Over limit

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => $overMaxContent,
            ]);

        $response->assertUnprocessable();
    }

    // Special characters
    public function test_handles_unicode_content(): void
    {
        $conversation = $this->createConversation();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'สวัสดี 你好 🎉 émoji',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('messages', [
            'content' => 'สวัสดี 你好 🎉 émoji',
        ]);
    }

    public function test_handles_html_content_safely(): void
    {
        $conversation = $this->createConversation();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => '<script>alert("xss")</script>',
            ]);

        $response->assertCreated();
        // Should be stored/escaped safely
    }

    // Empty collections
    public function test_returns_empty_array_when_no_messages(): void
    {
        $conversation = $this->createConversation();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/conversations/{$conversation->id}/messages");

        $response->assertOk()
            ->assertJson(['data' => []])
            ->assertJsonCount(0, 'data');
    }

    // Pagination boundaries
    public function test_pagination_first_page(): void
    {
        $conversation = $this->createConversation();
        Message::factory()->count(25)->create(['conversation_id' => $conversation->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/conversations/{$conversation->id}/messages?page=1&per_page=10");

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_pagination_last_page(): void
    {
        $conversation = $this->createConversation();
        Message::factory()->count(25)->create(['conversation_id' => $conversation->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/conversations/{$conversation->id}/messages?page=3&per_page=10");

        $response->assertOk()
            ->assertJsonCount(5, 'data')  // Remaining items
            ->assertJsonPath('meta.current_page', 3);
    }

    public function test_pagination_beyond_last_page(): void
    {
        $conversation = $this->createConversation();
        Message::factory()->count(25)->create(['conversation_id' => $conversation->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/conversations/{$conversation->id}/messages?page=999");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // Resource not found
    public function test_returns_404_for_nonexistent_resource(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/bots/99999');

        $response->assertNotFound();
    }

    // Null handling
    public function test_handles_nullable_fields(): void
    {
        $bot = Bot::factory()->create([
            'user_id' => $this->user->id,
            'description' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/bots/{$bot->id}");

        $response->assertOk()
            ->assertJsonPath('data.description', null);
    }
}
```

**Why it's better:**
- Tests boundaries
- Tests special inputs
- Tests empty states
- Tests null values

## Test Coverage

| Edge Case | Test Scenarios |
|-----------|---------------|
| Empty | Empty string, empty array, null |
| Boundaries | At limit, over limit, zero |
| Special chars | Unicode, emoji, HTML |
| Collections | Empty list, single item, pagination |
| Not found | Invalid ID, deleted resource |

## Run Command

```bash
# Run edge case tests
php artisan test --filter "empty\|max\|boundary\|edge"
```

## Project-Specific Notes

**BotFacebook Edge Cases:**

```php
// RAG edge cases
class RAGServiceTest extends TestCase
{
    public function test_handles_empty_knowledge_base(): void
    {
        $bot = Bot::factory()->create();
        // No knowledge documents

        $context = app(RAGService::class)->retrieveContext($bot, 'query');

        $this->assertEmpty($context);
    }

    public function test_handles_query_with_only_stopwords(): void
    {
        $bot = $this->createBotWithKnowledge();

        $context = app(RAGService::class)->retrieveContext($bot, 'the a an');

        // Should not crash, return empty or minimal
        $this->assertIsArray($context);
    }
}

// Conversation edge cases
class ConversationTest extends TestCase
{
    public function test_handles_conversation_with_no_messages(): void
    {
        $conversation = Conversation::factory()->create();

        $summary = $conversation->getSummary();

        $this->assertEquals('No messages yet', $summary);
    }
}
```
