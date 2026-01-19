---
id: flow-001-message-tracing
title: Trace Message Through System
impact: HIGH
impactDescription: "Cannot identify where message gets lost"
category: flow
tags: [debugging, tracing, flow, logging]
relatedRules: [queue-001-failed-jobs, line-001-signature-validation]
---

## Symptom

- Message sent but no response
- Cannot determine where failure occurred
- Logs don't show complete picture
- Intermittent failures hard to diagnose

## Root Cause

- Insufficient logging at key points
- Missing correlation IDs
- Logs scattered across services
- No end-to-end tracing

## Diagnosis

### Quick Check

```bash
# Search for specific message in logs
railway logs --filter "message_id:abc123"

# Check all stages
grep "abc123" storage/logs/laravel.log
```

### Detailed Analysis

```sql
-- Trace message lifecycle
SELECT
    m.id,
    m.content,
    m.created_at as received_at,
    m.processed_at,
    m.replied_at,
    EXTRACT(EPOCH FROM (m.processed_at - m.created_at)) as processing_seconds,
    EXTRACT(EPOCH FROM (m.replied_at - m.processed_at)) as reply_seconds
FROM messages m
WHERE m.id = $message_id;
```

## Solution

### Fix Steps

1. **Add Correlation ID**
```php
// In WebhookController
public function handleLine(Request $request, $botId): Response
{
    $correlationId = Str::uuid()->toString();

    Log::withContext(['correlation_id' => $correlationId]);

    Log::info('Webhook received', [
        'platform' => 'line',
        'bot_id' => $botId,
        'event_count' => count($request->input('events', [])),
    ]);

    // Pass correlation ID to job
    dispatch(new ProcessLINEWebhook($event, $botId, $correlationId));

    return response('OK');
}
```

2. **Log at Every Stage**
```php
class ProcessLINEWebhook implements ShouldQueue
{
    public function handle(): void
    {
        Log::withContext(['correlation_id' => $this->correlationId]);

        Log::info('Stage 1: Job started');

        // Validate
        Log::info('Stage 2: Validation complete');

        // Process AI
        Log::info('Stage 3: AI processing started');
        $response = $this->generateResponse();
        Log::info('Stage 4: AI response received', ['length' => strlen($response)]);

        // Send reply
        Log::info('Stage 5: Sending reply');
        $this->sendReply($response);
        Log::info('Stage 6: Reply sent');
    }
}
```

3. **Create Trace Query**
```sql
-- Create view for message trace
CREATE VIEW message_trace AS
SELECT
    m.id as message_id,
    m.correlation_id,
    m.created_at as webhook_received,
    j.created_at as job_dispatched,
    j.reserved_at as job_started,
    m.processed_at as ai_complete,
    m.replied_at as reply_sent,
    f.failed_at as job_failed,
    f.exception as failure_reason
FROM messages m
LEFT JOIN jobs j ON j.payload->>'correlation_id' = m.correlation_id
LEFT JOIN failed_jobs f ON f.payload->>'correlation_id' = m.correlation_id;
```

### Code Example

```php
// Good: Comprehensive tracing
namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessageTracer
{
    private string $correlationId;
    private array $stages = [];

    public function __construct(?string $correlationId = null)
    {
        $this->correlationId = $correlationId ?? Str::uuid()->toString();
        Log::withContext(['correlation_id' => $this->correlationId]);
    }

    public function stage(string $name, array $context = []): void
    {
        $timestamp = microtime(true);
        $duration = empty($this->stages)
            ? 0
            : round(($timestamp - end($this->stages)['timestamp']) * 1000, 2);

        $this->stages[] = [
            'name' => $name,
            'timestamp' => $timestamp,
            'duration_ms' => $duration,
        ];

        Log::info("Trace: {$name}", array_merge($context, [
            'stage' => count($this->stages),
            'duration_ms' => $duration,
        ]));
    }

    public function complete(array $context = []): array
    {
        $totalDuration = empty($this->stages)
            ? 0
            : round((microtime(true) - $this->stages[0]['timestamp']) * 1000, 2);

        $summary = [
            'correlation_id' => $this->correlationId,
            'total_duration_ms' => $totalDuration,
            'stages' => array_column($this->stages, 'name'),
            'stage_count' => count($this->stages),
        ];

        Log::info('Trace complete', array_merge($summary, $context));

        return $summary;
    }

    public function error(string $stage, \Throwable $exception): void
    {
        Log::error("Trace failed at: {$stage}", [
            'stage' => $stage,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }
}

// Usage in job
class ProcessLINEWebhook implements ShouldQueue
{
    public function handle(): void
    {
        $tracer = new MessageTracer($this->correlationId);

        try {
            $tracer->stage('validation_start');
            $this->validateEvent();
            $tracer->stage('validation_complete');

            $tracer->stage('message_save');
            $message = $this->saveMessage();
            $tracer->stage('message_saved', ['message_id' => $message->id]);

            $tracer->stage('ai_request');
            $response = $this->generateResponse($message);
            $tracer->stage('ai_response', ['response_length' => strlen($response)]);

            $tracer->stage('reply_send');
            $this->sendReply($response);
            $tracer->stage('reply_sent');

            $tracer->complete(['status' => 'success']);

        } catch (\Exception $e) {
            $tracer->error('unknown', $e);
            throw $e;
        }
    }
}
```

## Prevention

- Always use correlation IDs
- Log at every stage boundary
- Include timestamps and durations
- Store traces for debugging
- Create trace visualization tools

## Debug Commands

```bash
# Generate trace report
php artisan message:trace {correlation_id}

# Find traces by time range
grep "correlation_id" storage/logs/laravel.log | \
  awk -F'"' '/2024-01-15 14:/ {print $4}' | sort -u

# Aggregate stage durations
grep "Trace:" storage/logs/laravel.log | \
  jq -s 'group_by(.stage) | map({stage: .[0].stage, avg_ms: (map(.duration_ms) | add / length)})'
```

## Project-Specific Notes

**BotFacebook Context:**
- Correlation ID stored in `messages.correlation_id`
- Trace stages: webhook_received → job_dispatched → ai_started → ai_complete → reply_sent
- Logs shipped to Railway with correlation filter
- Debug command: `php artisan bot:trace {message_id}`
