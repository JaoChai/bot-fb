---
id: unit-003-assertion-quality
title: Write Meaningful Assertions
impact: HIGH
impactDescription: "Weak assertions let bugs pass through tests"
category: unit
tags: [unit-test, assertions, phpunit, quality]
relatedRules: [unit-001-isolation, feature-003-response-format]
---

## Why This Matters

Weak assertions like `assertTrue($response !== null)` pass even when the code is broken. Meaningful assertions catch real bugs.

## Bad Example

```php
public function test_creates_bot(): void
{
    $service = app(BotService::class);
    $bot = $service->create($user, ['name' => 'Test']);

    // Weak assertions
    $this->assertTrue($bot !== null);
    $this->assertNotEmpty($bot->id);
    $this->assertTrue(true);  // Always passes!
}

public function test_processes_message(): void
{
    $response = $this->service->process($message);

    // Only checks type, not content
    $this->assertInstanceOf(Message::class, $response);
}

public function test_returns_list(): void
{
    $results = $this->service->getAll();

    // Only checks it's an array
    $this->assertIsArray($results);
}
```

**Why it's problematic:**
- Tests pass with broken code
- No meaningful validation
- False confidence in coverage
- Bugs slip through

## Good Example

```php
public function test_creates_bot_with_correct_data(): void
{
    $user = User::factory()->create();
    $service = app(BotService::class);

    $bot = $service->create($user, [
        'name' => 'Test Bot',
        'platform' => 'line',
    ]);

    // Specific assertions
    $this->assertInstanceOf(Bot::class, $bot);
    $this->assertEquals('Test Bot', $bot->name);
    $this->assertEquals('line', $bot->platform);
    $this->assertEquals($user->id, $bot->user_id);
    $this->assertTrue($bot->is_active);  // Default value

    // Database verification
    $this->assertDatabaseHas('bots', [
        'id' => $bot->id,
        'name' => 'Test Bot',
        'platform' => 'line',
    ]);
}

public function test_processes_message_creates_response(): void
{
    $response = $this->service->process($conversation, 'Hello');

    // Verify structure and content
    $this->assertInstanceOf(Message::class, $response);
    $this->assertEquals($conversation->id, $response->conversation_id);
    $this->assertEquals('assistant', $response->role);
    $this->assertNotEmpty($response->content);
    $this->assertIsInt($response->tokens_used);
    $this->assertGreaterThan(0, $response->tokens_used);
}

public function test_returns_paginated_results(): void
{
    Bot::factory()->count(15)->create(['user_id' => $this->user->id]);

    $results = $this->service->getAll($this->user, perPage: 10);

    $this->assertInstanceOf(LengthAwarePaginator::class, $results);
    $this->assertCount(10, $results->items());
    $this->assertEquals(15, $results->total());
    $this->assertEquals(2, $results->lastPage());

    // Verify data structure
    $first = $results->first();
    $this->assertArrayHasKey('id', $first->toArray());
    $this->assertArrayHasKey('name', $first->toArray());
}

public function test_throws_exception_for_invalid_input(): void
{
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('platform');

    $this->service->create($this->user, [
        'name' => 'Test',
        'platform' => 'invalid',
    ]);
}

public function test_returns_empty_array_when_no_results(): void
{
    $results = $this->service->search($this->user, 'nonexistent');

    $this->assertIsArray($results);
    $this->assertEmpty($results);
    $this->assertCount(0, $results);
}
```

**Why it's better:**
- Specific value assertions
- Database state verification
- Structure validation
- Edge case coverage

## Test Coverage

| Assertion Type | When to Use |
|----------------|-------------|
| assertEquals | Exact value match |
| assertDatabaseHas | Verify persistence |
| assertCount | Collection size |
| assertInstanceOf | Type checking |
| assertThrows | Exception testing |
| assertJsonStructure | API response shape |

## Run Command

```bash
# Run with verbose output to see assertion details
php artisan test --filter Unit -v
```

## Project-Specific Notes

**BotFacebook Assertion Patterns:**

```php
// Custom assertions for common patterns
trait BotFacebookAssertions
{
    protected function assertBotCreated(Bot $bot, array $expected): void
    {
        $this->assertInstanceOf(Bot::class, $bot);
        $this->assertDatabaseHas('bots', array_merge(
            ['id' => $bot->id],
            $expected
        ));
    }

    protected function assertMessageSaved(
        Conversation $conversation,
        string $content,
        string $role = 'user'
    ): void {
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => $content,
            'role' => $role,
        ]);
    }

    protected function assertApiResponse(
        TestResponse $response,
        int $status = 200
    ): void {
        $response->assertStatus($status)
            ->assertJsonStructure([
                'data',
                'meta' => ['timestamp'],
            ]);
    }
}
```
