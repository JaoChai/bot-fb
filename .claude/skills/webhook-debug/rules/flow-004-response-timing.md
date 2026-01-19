---
id: flow-004-response-timing
title: Optimize Response Timing
impact: MEDIUM
impactDescription: "Slow responses cause poor UX or platform timeouts"
category: flow
tags: [performance, timing, latency, optimization]
relatedRules: [queue-002-job-timeout, line-002-reply-token-expiry]
---

## Symptom

- Users wait too long for responses
- Platform shows "typing" forever
- Reply tokens expire before sending
- Timeouts from platform API

## Root Cause

- AI generation too slow
- Synchronous processing
- No streaming
- Queue delays
- Network latency

## Diagnosis

### Quick Check

```sql
-- Check response times
SELECT
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY response_time_ms) as p50,
    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms) as p95,
    PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY response_time_ms) as p99,
    MAX(response_time_ms) as max
FROM messages
WHERE created_at > NOW() - INTERVAL '24 hours'
AND response_time_ms IS NOT NULL;
```

### Detailed Analysis

```php
// Log timing breakdown
Log::info('Timing breakdown', [
    'webhook_to_job' => $jobStartTime - $webhookReceivedTime,
    'ai_generation' => $aiEndTime - $aiStartTime,
    'reply_send' => $replySentTime - $aiEndTime,
    'total' => $replySentTime - $webhookReceivedTime,
]);
```

## Solution

### Fix Steps

1. **Acknowledge Quickly**
```php
// WebhookController - return 200 ASAP
public function handleLine(Request $request, $botId): Response
{
    // Parse and dispatch (no heavy processing)
    foreach ($request->input('events', []) as $event) {
        dispatch(new ProcessLINEWebhook($event, $botId));
    }

    // Return immediately
    return response('OK', 200);
}
```

2. **Send Typing Indicator**
```php
class ProcessLINEWebhook implements ShouldQueue
{
    public function handle(): void
    {
        // Send typing indicator first
        $this->sendTypingIndicator();

        // Then do slow processing
        $response = $this->generateResponse();

        $this->sendReply($response);
    }

    private function sendTypingIndicator(): void
    {
        // LINE doesn't have typing indicator
        // For Telegram:
        // $this->telegram->sendChatAction($chatId, 'typing');
    }
}
```

3. **Use Streaming for Long Responses**
```php
class StreamingResponseService
{
    public function generateAndStream(Message $message): void
    {
        $chunks = $this->aiService->streamResponse($message->content);

        $buffer = '';
        $lastSent = microtime(true);

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            // Send every 2 seconds or when buffer is large
            if (strlen($buffer) > 200 || (microtime(true) - $lastSent) > 2) {
                $this->sendPartialResponse($message, $buffer);
                $lastSent = microtime(true);
            }
        }

        // Send final chunk
        if ($buffer) {
            $this->sendFinalResponse($message, $buffer);
        }
    }
}
```

### Code Example

```php
// Good: Optimized response flow
namespace App\Jobs;

use App\Models\Message;
use App\Services\{RAGService, ModelTierSelector};
use Illuminate\Support\Facades\Log;

class ProcessLINEWebhook implements ShouldQueue
{
    public int $timeout = 30; // LINE reply token expires in 30s

    public function handle(
        RAGService $rag,
        ModelTierSelector $modelSelector
    ): void {
        $startTime = microtime(true);

        // Step 1: Quick analysis (< 1s)
        $complexity = $this->analyzeComplexity();

        // Step 2: Select appropriate model based on complexity and time budget
        $model = $modelSelector->selectForTimeBudget(
            $complexity,
            timeBudgetMs: 25000 // Leave 5s buffer for reply
        );

        Log::info('Model selected', [
            'complexity' => $complexity,
            'model' => $model,
            'time_budget_ms' => 25000,
        ]);

        // Step 3: Generate response with timeout
        $response = $rag->generateWithTimeout(
            $this->event['message']['text'],
            $this->getConversation(),
            model: $model,
            timeout: 20 // 20 seconds max
        );

        $aiTime = microtime(true) - $startTime;

        // Step 4: Send reply quickly
        $this->sendReply($response);

        $totalTime = microtime(true) - $startTime;

        // Step 5: Log timing
        Log::info('Response timing', [
            'message_id' => $this->event['message']['id'],
            'complexity' => $complexity,
            'model' => $model,
            'ai_time_ms' => round($aiTime * 1000),
            'total_time_ms' => round($totalTime * 1000),
        ]);

        // Alert if slow
        if ($totalTime > 20) {
            Log::warning('Slow response', [
                'total_time_s' => round($totalTime, 2),
                'message_id' => $this->event['message']['id'],
            ]);
        }
    }

    private function analyzeComplexity(): string
    {
        $text = $this->event['message']['text'];

        // Quick heuristics
        $wordCount = str_word_count($text);
        $hasQuestion = str_contains($text, '?');
        $hasCodeBlock = str_contains($text, '```');

        if ($hasCodeBlock || $wordCount > 100) {
            return 'high';
        } elseif ($hasQuestion || $wordCount > 30) {
            return 'medium';
        }

        return 'low';
    }
}

// Model selection with time awareness
class ModelTierSelector
{
    private array $modelSpeeds = [
        'haiku' => 50,    // ~50 tokens/sec
        'sonnet' => 30,   // ~30 tokens/sec
        'opus' => 15,     // ~15 tokens/sec
    ];

    public function selectForTimeBudget(string $complexity, int $timeBudgetMs): string
    {
        $expectedOutputTokens = match($complexity) {
            'high' => 500,
            'medium' => 200,
            default => 100,
        };

        // Calculate which model can finish in time
        $timeBudgetSec = $timeBudgetMs / 1000;

        foreach (['haiku', 'sonnet', 'opus'] as $model) {
            $speed = $this->modelSpeeds[$model];
            $estimatedTime = $expectedOutputTokens / $speed;

            if ($estimatedTime < $timeBudgetSec * 0.8) { // 80% buffer
                // Upgrade for complex queries
                if ($complexity === 'high' && $model === 'haiku') {
                    continue; // Skip haiku for complex
                }
                return $model;
            }
        }

        return 'haiku'; // Fallback to fastest
    }
}
```

## Prevention

- Set response time SLOs
- Use model tier selection
- Implement streaming where possible
- Monitor response time percentiles
- Alert on slow responses

## Debug Commands

```bash
# Check response time distribution
php artisan tinker
>>> Message::whereNotNull('response_time_ms')
...   ->selectRaw('
...       PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY response_time_ms) as p50,
...       PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms) as p95
...   ')->first();

# Find slow messages
>>> Message::where('response_time_ms', '>', 10000)
...   ->orderByDesc('response_time_ms')
...   ->limit(10)
...   ->get(['id', 'content', 'response_time_ms']);

# Profile AI generation
php artisan ai:benchmark --samples=10
```

## Project-Specific Notes

**BotFacebook Context:**
- Target p95: < 10 seconds
- LINE reply token: 30 seconds
- Model tier selection in `ModelTierSelector`
- Timing logged to `messages.response_time_ms`
