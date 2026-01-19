---
id: rerank-003-jina-api-error
title: Jina Reranker API Errors
impact: MEDIUM
impactDescription: "Reranking fails, falls back to semantic ordering"
category: rerank
tags: [reranker, jina, api, error]
relatedRules: [rerank-001-filter-too-much, embed-004-generation-failure]
---

## Symptom

- "Jina API error" in logs
- Reranker returns empty unexpectedly
- 401/403/429 errors
- Timeout during reranking

## Root Cause

1. Invalid API key
2. Rate limit exceeded
3. Request too large
4. API service outage
5. Network timeout

## Diagnosis

### Quick Check

```bash
# Test API key
curl https://api.jina.ai/v1/rerank \
  -H "Authorization: Bearer $JINA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "jina-reranker-v2-base-multilingual",
    "query": "test query",
    "documents": ["document 1", "document 2"]
  }'
```

### Detailed Analysis

```php
// Check recent errors
Log::channel('rag')
    ->where('message', 'LIKE', '%Jina%')
    ->where('level', 'error')
    ->latest()
    ->get();

// Test with minimal request
try {
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . config('services.jina.api_key'),
    ])->post('https://api.jina.ai/v1/rerank', [
        'model' => 'jina-reranker-v2-base-multilingual',
        'query' => 'test',
        'documents' => ['test doc'],
    ]);

    Log::info('Jina test', [
        'status' => $response->status(),
        'body' => $response->body(),
    ]);
} catch (\Exception $e) {
    Log::error('Jina test failed', ['error' => $e->getMessage()]);
}
```

## Solution

### Fix Steps

1. **Verify API key**
```php
// Check key is set
dd(config('services.jina.api_key'));
// Should not be null/empty

// Verify in Jina dashboard
// https://jina.ai/dashboard
```

2. **Handle rate limits**
```php
// Add retry with backoff
class JinaRerankerService
{
    public function rerankWithRetry(string $query, Collection $documents): Collection
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                return $this->rerank($query, $documents);
            } catch (RateLimitException $e) {
                $attempt++;
                $wait = $e->getRetryAfter() ?? pow(2, $attempt);
                Log::warning('Jina rate limited', ['retry_in' => $wait]);
                sleep($wait);
            }
        }

        // Fallback
        Log::error('Jina failed after retries');
        return $documents->take(10);
    }
}
```

3. **Reduce request size**
```php
// Limit documents sent
'reranker' => [
    'max_documents' => 30,  // Reduce if hitting limits
    'max_doc_length' => 500,  // Truncate documents
],
```

### Code Fix

```php
// Robust Jina integration
class JinaRerankerService
{
    private int $maxRetries = 3;
    private int $timeout = 30;

    public function rerank(string $query, Collection $documents): Collection
    {
        if ($documents->isEmpty()) {
            return collect();
        }

        // Limit input size
        $documents = $documents->take(config('rag.reranker.max_documents', 30));
        $prepared = $this->prepareDocuments($documents);

        try {
            $response = $this->callAPIWithRetry($query, $prepared);
            return $this->processResponse($response, $documents);
        } catch (JinaAPIException $e) {
            Log::error('Jina reranker failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            // Fallback to semantic ordering
            return $documents->take(10);
        }
    }

    private function callAPIWithRetry(string $query, array $documents): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . config('services.jina.api_key'),
                    ])
                    ->post('https://api.jina.ai/v1/rerank', [
                        'model' => config('rag.reranker.model'),
                        'query' => $query,
                        'documents' => $documents,
                        'top_n' => count($documents),
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }

                $this->handleErrorResponse($response, $attempt);

            } catch (ConnectionException $e) {
                Log::warning('Jina connection error', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                $lastException = $e;
                sleep(pow(2, $attempt));
            }
        }

        throw new JinaAPIException(
            'Failed after ' . $this->maxRetries . ' attempts',
            0,
            $lastException
        );
    }

    private function handleErrorResponse(Response $response, int $attempt): void
    {
        $status = $response->status();

        match ($status) {
            401 => throw new JinaAPIException('Invalid API key'),
            403 => throw new JinaAPIException('API key forbidden'),
            429 => $this->handleRateLimit($response, $attempt),
            500, 502, 503 => $this->handleServerError($attempt),
            default => throw new JinaAPIException("Unexpected status: {$status}"),
        };
    }

    private function handleRateLimit(Response $response, int $attempt): void
    {
        $retryAfter = $response->header('Retry-After') ?? pow(2, $attempt);
        Log::warning('Jina rate limited', ['retry_after' => $retryAfter]);
        sleep($retryAfter);
    }

    private function prepareDocuments(Collection $documents): array
    {
        $maxLength = config('rag.reranker.max_doc_length', 500);

        return $documents->map(function ($doc) use ($maxLength) {
            $text = strip_tags($doc->content);
            return mb_substr($text, 0, $maxLength);
        })->toArray();
    }
}
```

## Verification

```php
// Test Jina integration
$result = app(JinaRerankerService::class)->rerank(
    'test query',
    KnowledgeBaseDocument::limit(5)->get()
);

assert($result->isNotEmpty(), 'Jina should return results');
Log::info('Jina verification passed');

// Check health endpoint if available
$health = Http::get('https://api.jina.ai/health');
Log::info('Jina health', ['status' => $health->status()]);
```

## Prevention

- Monitor API key expiration
- Set up rate limit alerts
- Implement circuit breaker
- Log all API errors
- Have fallback strategy

## Project-Specific Notes

**BotFacebook Context:**
- API key: `JINA_API_KEY` in env
- Fallback: Semantic order if Jina fails
- Timeout: 30 seconds
- Max documents: 30 per request
