---
id: unit-002-mock-external
title: Mock External Services Properly
impact: CRITICAL
impactDescription: "Over-mocking hides bugs, under-mocking causes flaky tests"
category: unit
tags: [unit-test, mocking, mockery, http-fake]
relatedRules: [unit-001-isolation, unit-003-assertion-quality]
---

## Why This Matters

Proper mocking tests behavior while isolating from external systems. Over-mocking creates tests that pass but don't catch bugs. Under-mocking creates flaky tests.

## Bad Example

```php
// Over-mocking - testing implementation, not behavior
class BotServiceTest extends TestCase
{
    public function test_creates_bot(): void
    {
        $mockRepo = Mockery::mock(BotRepository::class);
        $mockRepo->shouldReceive('create')
            ->once()
            ->with(['name' => 'Test'])
            ->andReturn(new Bot(['name' => 'Test']));

        $service = new BotService($mockRepo);
        $bot = $service->create(['name' => 'Test']);

        // This test passes even if service logic is wrong
        $this->assertEquals('Test', $bot->name);
    }
}

// Under-mocking - still depends on real services
class ChatServiceTest extends TestCase
{
    public function test_sends_message(): void
    {
        // Only mocks one dependency, others are real
        Http::fake(['openrouter.ai/*' => Http::response([...])]);

        $service = app(ChatService::class);
        // Still calls real embedding service!
        $service->process($message);
    }
}
```

**Why it's problematic:**
- Over-mocking tests implementation details
- Tests pass but bugs slip through
- Under-mocking causes unexpected failures
- Hard to maintain when implementation changes

## Good Example

```php
// Test behavior through interfaces
class BotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_bot_with_defaults(): void
    {
        $user = User::factory()->create();

        // Use real database, mock external APIs
        $service = app(BotService::class);
        $bot = $service->create($user, [
            'name' => 'Test Bot',
            'platform' => 'line',
        ]);

        $this->assertDatabaseHas('bots', [
            'name' => 'Test Bot',
            'platform' => 'line',
            'user_id' => $user->id,
            'is_active' => true,  // Test default
        ]);
    }
}

// Mock at the right boundary
class ChatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_message_with_rag(): void
    {
        // Mock ALL external services
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'AI response']]],
            ]),
        ]);

        $mockEmbedding = $this->mockEmbeddingService();
        $mockSearch = $this->mockSearchService();

        $bot = Bot::factory()->create();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        $service = app(ChatService::class);
        $response = $service->process($conversation, 'User message');

        $this->assertEquals('AI response', $response->content);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'User message',
        ]);
    }

    private function mockEmbeddingService(): void
    {
        $mock = Mockery::mock(EmbeddingService::class);
        $mock->shouldReceive('generate')
            ->andReturn(array_fill(0, 1536, 0.1));
        $this->app->instance(EmbeddingService::class, $mock);
    }

    private function mockSearchService(): void
    {
        $mock = Mockery::mock(SemanticSearchService::class);
        $mock->shouldReceive('search')
            ->andReturn(collect());
        $this->app->instance(SemanticSearchService::class, $mock);
    }
}

// HTTP Fake for external APIs
class LINEServiceTest extends TestCase
{
    public function test_sends_message(): void
    {
        Http::fake([
            'api.line.me/*' => Http::response(['success' => true]),
        ]);

        $service = new LINEService($this->createBot());
        $service->sendMessage('user123', 'Hello');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && $request['to'] === 'user123'
                && $request['messages'][0]['text'] === 'Hello';
        });
    }
}
```

**Why it's better:**
- Tests behavior, not implementation
- Mocks at right boundaries
- Real database for data tests
- Verifies HTTP requests

## Test Coverage

| Layer | Mock Strategy |
|-------|--------------|
| External APIs | Http::fake() |
| LLM Services | Interface mock |
| Database | Real with RefreshDatabase |
| Cache | Real or Cache::fake() |
| File System | Storage::fake() |

## Run Command

```bash
# Run with coverage to ensure mocking doesn't hide gaps
php artisan test --filter Unit --coverage
```

## Project-Specific Notes

**BotFacebook Mocking Patterns:**

```php
// Base test class with common mocks
abstract class ServiceTestCase extends TestCase
{
    use RefreshDatabase;

    protected function mockAllLLMServices(): void
    {
        Http::fake([
            'openrouter.ai/*' => $this->llmResponse(),
        ]);

        $this->mockEmbeddingService();
    }

    protected function llmResponse(string $content = 'AI response'): \Closure
    {
        return Http::response([
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['total_tokens' => 100],
        ]);
    }

    protected function mockEmbeddingService(): void
    {
        $mock = Mockery::mock(EmbeddingService::class);
        $mock->shouldReceive('generate')->andReturn(array_fill(0, 1536, 0.1));
        $this->app->instance(EmbeddingService::class, $mock);
    }
}
```
