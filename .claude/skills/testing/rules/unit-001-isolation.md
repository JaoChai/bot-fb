---
id: unit-001-isolation
title: Isolate Unit Tests from External Dependencies
impact: CRITICAL
impactDescription: "Tests pass locally but fail in CI due to external dependencies"
category: unit
tags: [unit-test, isolation, mocking, phpunit]
relatedRules: [unit-002-mock-external, unit-003-assertion-quality]
---

## Why This Matters

Unit tests that depend on external services (APIs, databases, file systems) are slow, flaky, and can fail due to network issues rather than code bugs.

## Bad Example

```php
class OpenRouterServiceTest extends TestCase
{
    public function test_generates_response(): void
    {
        $service = new OpenRouterService();

        // Hits real API - slow, costs money, can fail
        $response = $service->complete('Hello');

        $this->assertNotEmpty($response);
    }
}

class EmbeddingServiceTest extends TestCase
{
    public function test_creates_embedding(): void
    {
        // Needs real database
        $document = KnowledgeDocument::factory()->create();

        $service = new EmbeddingService();
        // Hits real embedding API
        $service->generateForDocument($document);
    }
}
```

**Why it's problematic:**
- Tests hit real APIs (slow, costly)
- Tests depend on network
- Tests fail in CI without credentials
- Can't test error scenarios

## Good Example

```php
class OpenRouterServiceTest extends TestCase
{
    public function test_generates_response(): void
    {
        // Mock the HTTP client
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Hello!']]],
            ]),
        ]);

        $service = app(OpenRouterService::class);
        $response = $service->complete('Hello');

        $this->assertEquals('Hello!', $response);
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
        );
    }

    public function test_handles_api_error(): void
    {
        // Test error handling
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => 'Rate limited'], 429),
        ]);

        $service = app(OpenRouterService::class);

        $this->expectException(RateLimitException::class);
        $service->complete('Hello');
    }
}

class EmbeddingServiceTest extends TestCase
{
    public function test_creates_embedding(): void
    {
        // Mock embedding API
        $mockClient = Mockery::mock(EmbeddingClient::class);
        $mockClient->shouldReceive('embed')
            ->once()
            ->with('Document content')
            ->andReturn(array_fill(0, 1536, 0.1));

        $this->app->instance(EmbeddingClient::class, $mockClient);

        $service = app(EmbeddingService::class);
        $embedding = $service->generate('Document content');

        $this->assertCount(1536, $embedding);
    }
}
```

**Why it's better:**
- No external dependencies
- Fast execution
- Can test error scenarios
- Deterministic results

## Test Coverage

| Scenario | Priority |
|----------|----------|
| Happy path | Must test |
| API errors | Must test |
| Timeout handling | Should test |
| Rate limiting | Should test |
| Invalid responses | Nice to have |

## Run Command

```bash
# Run unit tests only
php artisan test --filter Unit

# Run specific service test
php artisan test --filter OpenRouterServiceTest
```

## Project-Specific Notes

**BotFacebook Service Testing:**

```php
// tests/Unit/Services/RAGServiceTest.php
class RAGServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrieves_relevant_context(): void
    {
        // Mock embedding service
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('generate')
            ->andReturn(array_fill(0, 1536, 0.1));
        $this->app->instance(EmbeddingService::class, $mockEmbedding);

        // Mock search service
        $mockSearch = Mockery::mock(SemanticSearchService::class);
        $mockSearch->shouldReceive('search')
            ->andReturn(collect([
                new SearchResult('Relevant content', 0.85),
            ]));
        $this->app->instance(SemanticSearchService::class, $mockSearch);

        $service = app(RAGService::class);
        $context = $service->retrieveContext('query', $botId = 1);

        $this->assertNotEmpty($context);
    }
}
```
