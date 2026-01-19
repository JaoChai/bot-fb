---
id: embed-004-generation-failure
title: Embedding Generation Fails
impact: HIGH
impactDescription: "New documents cannot be indexed"
category: embed
tags: [embedding, api, openai, generation]
relatedRules: [embed-001-null-embedding, thresh-003-context-limit]
---

## Symptom

- Document upload succeeds but embedding is NULL
- API errors in logs
- Rate limit messages
- Timeout during embedding generation

## Root Cause

1. OpenAI API key invalid/expired
2. Rate limit exceeded
3. Content too long for model
4. Network timeout
5. API service outage

## Diagnosis

### Quick Check

```bash
# Check API key
echo $OPENAI_API_KEY | head -c 10

# Test API directly
curl https://api.openai.com/v1/embeddings \
  -H "Authorization: Bearer $OPENAI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"input": "test", "model": "text-embedding-3-large"}'
```

### Detailed Analysis

```php
// Check recent errors
Log::channel('rag')
    ->where('level', 'error')
    ->where('context.service', 'embedding')
    ->latest()
    ->limit(10);

// Test embedding generation
try {
    $embedding = app(EmbeddingService::class)->generate('test query');
    Log::info('Embedding test successful', ['dimensions' => count($embedding)]);
} catch (\Exception $e) {
    Log::error('Embedding test failed', ['error' => $e->getMessage()]);
}
```

## Solution

### Fix Steps

1. **Check API key validity**
```php
// Test in tinker
$response = OpenAI::models()->list();
dd($response); // Should list available models
```

2. **Handle rate limits**
```php
class EmbeddingService
{
    public function generateWithRetry(string $text, int $maxRetries = 3): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                return $this->generate($text);
            } catch (RateLimitException $e) {
                $attempt++;
                $waitTime = $e->getRetryAfter() ?? (pow(2, $attempt) * 1000);
                Log::warning('Rate limited, retrying', [
                    'attempt' => $attempt,
                    'wait_ms' => $waitTime,
                ]);
                usleep($waitTime * 1000);
                $lastException = $e;
            }
        }

        throw $lastException;
    }
}
```

3. **Handle long content**
```php
public function generateForLongContent(string $text): array
{
    $maxTokens = 8191; // text-embedding-3-large limit
    $estimatedTokens = strlen($text) / 4; // rough estimate

    if ($estimatedTokens > $maxTokens) {
        // Truncate or chunk
        $text = mb_substr($text, 0, $maxTokens * 3); // ~3 chars per token
    }

    return $this->generate($text);
}
```

### Code Fix

```php
// Robust embedding service
class EmbeddingService
{
    private OpenAIClient $client;
    private CircuitBreaker $circuitBreaker;

    public function generate(string $text): array
    {
        // Validate input
        if (empty(trim($text))) {
            throw new InvalidArgumentException('Cannot generate embedding for empty text');
        }

        // Check circuit breaker
        return $this->circuitBreaker->call('openai-embeddings', function () use ($text) {
            try {
                $response = $this->client->embeddings()->create([
                    'model' => config('rag.embedding.model'),
                    'input' => $this->prepareInput($text),
                ]);

                return $response->embeddings[0]->embedding;

            } catch (ClientException $e) {
                $this->handleApiError($e);
                throw $e;
            }
        });
    }

    private function prepareInput(string $text): string
    {
        // Clean and truncate
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text); // Normalize whitespace

        // Truncate if too long (rough token estimate)
        $maxChars = 8000 * 3; // ~3 chars per token
        if (strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars);
        }

        return $text;
    }

    private function handleApiError(ClientException $e): void
    {
        $status = $e->getCode();

        match ($status) {
            401 => Log::error('OpenAI API key invalid'),
            429 => Log::warning('OpenAI rate limit hit'),
            500, 502, 503 => Log::error('OpenAI service error'),
            default => Log::error('OpenAI API error', ['status' => $status]),
        };
    }
}
```

## Verification

```php
// Test embedding generation
$embedding = app(EmbeddingService::class)->generate('Test embedding generation');
assert(count($embedding) === 3072, 'Wrong dimension');
echo "Embedding generation working!";

// Check all documents now have embeddings
$nullCount = KnowledgeBaseDocument::whereNull('embedding')->count();
echo "Documents without embeddings: {$nullCount}";
```

## Prevention

- Monitor API usage and limits
- Implement circuit breaker
- Queue embedding generation
- Set up alerts for API errors
- Use retry with exponential backoff

## Project-Specific Notes

**BotFacebook Context:**
- API key in `OPENAI_API_KEY` env var
- Circuit breaker via `CircuitBreakerService`
- Rate limit handling in `OpenRouterService` (shared)
- Max input: ~8000 tokens
