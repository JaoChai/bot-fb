---
id: flow-002-idempotency
title: Implement Idempotent Processing
impact: HIGH
impactDescription: "Duplicate processing causes data corruption or duplicate messages"
category: flow
tags: [idempotency, duplicate, webhook, safety]
relatedRules: [queue-005-duplicate-processing, line-001-signature-validation]
---

## Symptom

- Same message processed multiple times
- Duplicate responses sent to users
- Data duplication in database
- Race conditions between retries

## Root Cause

- Platform retrying webhooks
- Job retry after timeout
- Missing idempotency checks
- No deduplication at entry point

## Diagnosis

### Quick Check

```sql
-- Find duplicate webhook events
SELECT
    platform_event_id,
    COUNT(*) as count
FROM webhook_logs
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY platform_event_id
HAVING COUNT(*) > 1;
```

### Detailed Analysis

```php
// Log all webhook attempts
Log::info('Webhook received', [
    'platform_event_id' => $event['webhookEventId'],
    'is_duplicate' => Cache::has("event:{$event['webhookEventId']}"),
]);
```

## Solution

### Fix Steps

1. **Deduplicate at Webhook Level**
```php
public function handleLine(Request $request, $botId): Response
{
    foreach ($request->input('events', []) as $event) {
        $eventId = $event['webhookEventId'];

        // Check if already received
        $cacheKey = "line_webhook:{$eventId}";
        if (Cache::has($cacheKey)) {
            Log::info('Duplicate webhook, skipping', ['event_id' => $eventId]);
            continue;
        }

        // Mark as received (24 hour TTL)
        Cache::put($cacheKey, true, now()->addHours(24));

        dispatch(new ProcessLINEWebhook($event, $botId));
    }

    return response('OK');
}
```

2. **Idempotent Job Processing**
```php
class ProcessIncomingMessage implements ShouldQueue
{
    public function handle(): void
    {
        // Atomic check-and-set
        $lockKey = "processing:{$this->message->id}";

        if (!Cache::add($lockKey, true, 300)) {
            Log::info('Already processing', ['message_id' => $this->message->id]);
            return;
        }

        try {
            $this->process();
        } finally {
            Cache::forget($lockKey);
        }
    }
}
```

3. **Database-Level Idempotency**
```php
// Use unique constraints
Schema::create('messages', function (Blueprint $table) {
    $table->string('platform_message_id')->unique();
});

// Upsert instead of insert
Message::updateOrCreate(
    ['platform_message_id' => $event['message']['id']],
    [
        'content' => $event['message']['text'],
        'received_at' => now(),
    ]
);
```

### Code Example

```php
// Good: Multi-level idempotency
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    /**
     * Level 1: Check if event was already received
     */
    public function isNewEvent(string $platform, string $eventId): bool
    {
        $key = "event:{$platform}:{$eventId}";

        // atomic add - returns false if already exists
        return Cache::add($key, now()->toISOString(), now()->addHours(24));
    }

    /**
     * Level 2: Acquire processing lock
     */
    public function acquireProcessingLock(string $resourceType, $resourceId, int $ttl = 300): ?Lock
    {
        $key = "lock:{$resourceType}:{$resourceId}";
        $lock = Cache::lock($key, $ttl);

        if ($lock->get()) {
            return $lock;
        }

        return null;
    }

    /**
     * Level 3: Check if already processed in database
     */
    public function isProcessed(string $table, string $column, string $value): bool
    {
        return DB::table($table)->where($column, $value)->exists();
    }

    /**
     * Level 4: Idempotent write with unique constraint
     */
    public function idempotentCreate(string $model, array $uniqueBy, array $attributes): mixed
    {
        return $model::firstOrCreate($uniqueBy, $attributes);
    }
}

// Usage in webhook controller
class WebhookController
{
    public function __construct(
        private IdempotencyService $idempotency
    ) {}

    public function handleLine(Request $request, $botId): Response
    {
        foreach ($request->input('events', []) as $event) {
            // Level 1: Event deduplication
            if (!$this->idempotency->isNewEvent('line', $event['webhookEventId'])) {
                continue;
            }

            dispatch(new ProcessLINEWebhook($event, $botId));
        }

        return response('OK');
    }
}

// Usage in job
class ProcessLINEWebhook implements ShouldQueue
{
    public function handle(IdempotencyService $idempotency): void
    {
        // Level 2: Processing lock
        $lock = $idempotency->acquireProcessingLock(
            'message',
            $this->event['message']['id']
        );

        if (!$lock) {
            Log::info('Already processing');
            return;
        }

        try {
            // Level 3: Check database
            if ($idempotency->isProcessed('messages', 'platform_message_id', $this->getMessageId())) {
                return;
            }

            // Level 4: Idempotent create
            $message = $idempotency->idempotentCreate(
                Message::class,
                ['platform_message_id' => $this->getMessageId()],
                [
                    'content' => $this->getContent(),
                    'bot_id' => $this->botId,
                    // ...
                ]
            );

            $this->processMessage($message);

        } finally {
            $lock->release();
        }
    }
}
```

## Prevention

- Always deduplicate at entry point
- Use unique constraints in database
- Implement processing locks
- Test with concurrent requests
- Monitor for duplicate patterns

## Debug Commands

```bash
# Find duplicate events
redis-cli KEYS "event:*" | wc -l

# Check active locks
redis-cli KEYS "lock:*"

# Find duplicate messages in DB
php artisan tinker
>>> Message::selectRaw('platform_message_id, COUNT(*)')
...   ->groupByRaw('platform_message_id')
...   ->havingRaw('COUNT(*) > 1')
...   ->get();

# Test idempotency
curl -X POST $WEBHOOK_URL -d @event.json  # First time
curl -X POST $WEBHOOK_URL -d @event.json  # Should be skipped
```

## Project-Specific Notes

**BotFacebook Context:**
- LINE: Use `webhookEventId` for deduplication
- Telegram: Use `update_id` for deduplication
- Lock TTL: 5 minutes for message processing
- `platform_message_id` unique in `messages` table
