---
id: unit-006-arrange-act-assert
title: Follow Arrange-Act-Assert Pattern
impact: MEDIUM
impactDescription: "Unstructured tests are hard to read and maintain"
category: unit
tags: [unit-test, aaa-pattern, structure, phpunit]
relatedRules: [unit-003-assertion-quality, unit-005-test-naming]
---

## Why This Matters

The AAA pattern (Arrange-Act-Assert) creates predictable, readable tests. Each test has clear setup, execution, and verification phases.

## Bad Example

```php
public function test_bot_service(): void
{
    $user = User::factory()->create();
    $bot = $this->service->create($user, ['name' => 'Test']);
    $this->assertEquals('Test', $bot->name);
    $bot2 = $this->service->create($user, ['name' => 'Test2']);
    $this->assertEquals('Test2', $bot2->name);
    $this->assertCount(2, $user->bots);
    $this->service->deactivate($bot);
    $this->assertFalse($bot->fresh()->is_active);
    // What is this test even testing?
}

public function test_complex_flow(): void
{
    // 50 lines of mixed setup, actions, and assertions
    // Impossible to understand
}
```

**Why it's problematic:**
- Multiple actions in one test
- Hard to identify what's being tested
- Debugging failures is difficult
- Tests become documentation nightmare

## Good Example

```php
public function test_creates_bot_with_provided_name(): void
{
    // Arrange
    $user = User::factory()->create();
    $service = app(BotService::class);

    // Act
    $bot = $service->create($user, [
        'name' => 'Test Bot',
        'platform' => 'line',
    ]);

    // Assert
    $this->assertEquals('Test Bot', $bot->name);
    $this->assertDatabaseHas('bots', ['name' => 'Test Bot']);
}

public function test_deactivates_bot(): void
{
    // Arrange
    $bot = Bot::factory()->create(['is_active' => true]);
    $service = app(BotService::class);

    // Act
    $service->deactivate($bot);

    // Assert
    $this->assertFalse($bot->fresh()->is_active);
}

public function test_counts_user_bots(): void
{
    // Arrange
    $user = User::factory()->create();
    Bot::factory()->count(3)->create(['user_id' => $user->id]);

    // Act
    $count = $user->bots()->count();

    // Assert
    $this->assertEquals(3, $count);
}

// Complex scenarios - still AAA
public function test_processes_message_with_rag_context(): void
{
    // Arrange
    $bot = Bot::factory()
        ->has(KnowledgeBase::factory()->has(
            KnowledgeDocument::factory()->count(3)
        ))
        ->create();
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $this->mockLLMService();

    // Act
    $response = app(ChatService::class)->process($conversation, 'Hello');

    // Assert
    $this->assertInstanceOf(Message::class, $response);
    $this->assertEquals('assistant', $response->role);
    $this->assertNotEmpty($response->content);
}
```

**Why it's better:**
- Clear test phases
- One concept per test
- Easy to debug
- Self-documenting

## Test Coverage

| Phase | Content |
|-------|---------|
| Arrange | Create data, mock services |
| Act | Single action being tested |
| Assert | Verify results and side effects |

## Run Command

```bash
# Run single test to verify structure
php artisan test --filter test_creates_bot_with_provided_name
```

## Project-Specific Notes

**BotFacebook AAA Patterns:**

```php
// Service test with full AAA
class ChatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_user_message_and_generates_response(): void
    {
        // ========== Arrange ==========
        // Create test data
        $bot = Bot::factory()->create();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        // Mock external services
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Hello!']]],
                'usage' => ['total_tokens' => 50],
            ]),
        ]);

        $this->mockEmbeddingService();

        // ========== Act ==========
        $response = app(ChatService::class)->process(
            $conversation,
            'Hi there'
        );

        // ========== Assert ==========
        // Verify response
        $this->assertInstanceOf(Message::class, $response);
        $this->assertEquals('Hello!', $response->content);
        $this->assertEquals('assistant', $response->role);

        // Verify side effects
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Hi there',
            'role' => 'user',
        ]);

        // Verify external calls
        Http::assertSent(fn ($request) =>
            str_contains($request->url(), 'openrouter.ai')
        );
    }

    private function mockEmbeddingService(): void
    {
        $mock = Mockery::mock(EmbeddingService::class);
        $mock->shouldReceive('generate')
            ->andReturn(array_fill(0, 1536, 0.1));
        $this->app->instance(EmbeddingService::class, $mock);
    }
}
```
