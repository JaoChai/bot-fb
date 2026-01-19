---
id: queue-005-duplicate-processing
title: Jobs Processed Multiple Times
impact: MEDIUM
impactDescription: "Duplicate messages, duplicate actions, data inconsistency"
category: queue
tags: [queue, duplicate, idempotency, at-least-once]
relatedRules: [queue-004-retry-strategy, flow-002-idempotency]
---

## Symptom

- Same message sent multiple times
- Duplicate entries in database
- Actions triggered twice
- User receives duplicate notifications

## Root Cause

1. Job timeout causing retry while still processing
2. Worker crash during processing
3. Platform webhook retry hitting before job completes
4. Multiple workers processing same job
5. Missing idempotency checks

## Diagnosis

### Quick Check

```sql
-- Check for duplicate messages
SELECT
    conversation_id,
    content,
    role,
    COUNT(*) as count
FROM messages
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY conversation_id, content, role
HAVING COUNT(*) > 1;
```

### Detailed Analysis

```php
// Add logging to detect duplicates
Log::info('Processing message', [
    'message_id' => $message->id,
    'job_uuid' => $this->job->uuid(),
    'attempt' => $this->attempts(),
]);
```

## Solution

### Fix Steps

1. **Add Idempotency Key**
```php
class ProcessIncomingMessage implements ShouldQueue
{
    public function handle(): void
    {
        $idempotencyKey = "process_message:{$this->message->id}";

        // Check if already processed
        if (Cache::has($idempotencyKey)) {
            Log::info('Message already processed, skipping', [
                'message_id' => $this->message->id,
            ]);
            return;
        }

        // Lock and process
        Cache::put($idempotencyKey, true, now()->addHours(24));

        $this->processMessage();
    }
}
```

2. **Use Database Lock**
```php
public function handle(): void
{
    DB::transaction(function () {
        // Lock the message row
        $message = Message::lockForUpdate()->find($this->message->id);

        if ($message->processed_at) {
            return; // Already processed
        }

        $this->processMessage($message);

        $message->update(['processed_at' => now()]);
    });
}
```

3. **Prevent Platform Retry Duplicates**
```php
// Webhook controller
public function handleLine(Request $request, $botId): Response
{
    foreach ($request->input('events', []) as $event) {
        $eventId = $event['webhookEventId'] ?? null;

        if ($eventId && Cache::has("line_event:{$eventId}")) {
            continue; // Skip duplicate
        }

        if ($eventId) {
            Cache::put("line_event:{$eventId}", true, now()->addHours(24));
        }

        dispatch(new ProcessLINEWebhook($event, $botId));
    }

    return response('OK');
}
```

### Code Example

```php
// Good: Idempotent job processing
namespace App\Jobs;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessage implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    private string $processingLockKey;

    public function __construct(
        public Message $message
    ) {
        $this->processingLockKey = "processing_message:{$message->id}";
    }

    public function handle(): void
    {
        // Acquire processing lock (5 minute timeout)
        $lock = Cache::lock($this->processingLockKey, 300);

        if (!$lock->get()) {
            Log::info('Message already being processed', [
                'message_id' => $this->message->id,
            ]);
            // Release back to queue for later
            $this->release(30);
            return;
        }

        try {
            // Double-check not already processed
            $message = Message::find($this->message->id);

            if ($message->processed_at) {
                Log::info('Message already processed', [
                    'message_id' => $message->id,
                    'processed_at' => $message->processed_at,
                ]);
                return;
            }

            // Process the message
            $response = $this->generateResponse($message);

            // Save response (idempotently)
            DB::transaction(function () use ($message, $response) {
                // Refresh to check again
                $message->refresh();

                if ($message->processed_at) {
                    return;
                }

                $message->conversation->messages()->create([
                    'content' => $response,
                    'role' => 'assistant',
                ]);

                $message->update(['processed_at' => now()]);
            });

        } finally {
            $lock->release();
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->message->id;
    }

    // Prevent duplicate job dispatches
    public function uniqueFor(): int
    {
        return 300; // 5 minutes
    }
}

// Dispatch with unique constraint
dispatch(new ProcessIncomingMessage($message))->unique();
```

## Prevention

- Always use idempotency keys
- Track processed_at timestamps
- Use database locks for critical sections
- Implement webhook deduplication
- Use unique job constraints

## Debug Commands

```bash
# Find duplicate messages
php artisan tinker
>>> Message::selectRaw('conversation_id, content, COUNT(*)')
...   ->groupBy('conversation_id', 'content')
...   ->havingRaw('COUNT(*) > 1')
...   ->get();

# Check processing locks
redis-cli KEYS "processing_message:*"

# Clear stuck locks
redis-cli DEL "processing_message:123"
```

## Project-Specific Notes

**BotFacebook Context:**
- LINE webhookEventId used for deduplication
- Telegram update_id tracked for deduplication
- Processing lock in Redis with 5-minute timeout
- `messages.processed_at` marks completion
