---
id: webhook-003-replay-prevention
title: Prevent Webhook Replay Attacks
impact: MEDIUM
impactDescription: "Captured webhook requests replayed to trigger duplicate actions"
category: webhook
tags: [webhook, replay, idempotency, security]
relatedRules: [webhook-001-signature-validation, webhook-002-ip-whitelist]
---

## Why This Matters

Valid webhook requests can be captured and replayed. Without replay protection, duplicate messages are sent, duplicate orders placed, or actions repeated.

## Threat Model

**Attack Vector:** Captured webhook requests replayed
**Impact:** Duplicate processing, duplicate messages
**Likelihood:** Low - but easy to exploit if vulnerable

## Bad Example

```php
// No replay protection
public function handleWebhook(Request $request, Bot $bot)
{
    $events = $request->input('events');

    foreach ($events as $event) {
        // Same event can be processed multiple times!
        $this->processEvent($event, $bot);
    }
}

// No timestamp validation
public function handle(Request $request)
{
    // 10-hour old request still processed
    $this->process($request);
}
```

**Why it's vulnerable:**
- Same request processed multiple times
- Old requests still valid
- No idempotency tracking

## Good Example

```php
// Timestamp validation
class ValidateWebhookTimestamp
{
    private const MAX_AGE_SECONDS = 300; // 5 minutes

    public function handle(Request $request, Closure $next)
    {
        $timestamp = $request->input('timestamp')
            ?? $request->header('X-Line-Request-Timestamp');

        if (!$timestamp) {
            // No timestamp - use other protections
            return $next($request);
        }

        // Convert to seconds if milliseconds
        if ($timestamp > 1e12) {
            $timestamp = $timestamp / 1000;
        }

        $age = time() - (int) $timestamp;

        if ($age > self::MAX_AGE_SECONDS || $age < -60) {
            Log::warning('Webhook timestamp out of range', [
                'timestamp' => $timestamp,
                'age' => $age,
            ]);

            return response('OK', 200);
        }

        return $next($request);
    }
}

// Idempotency tracking
class LINEWebhookController extends Controller
{
    public function handle(Request $request, Bot $bot)
    {
        $events = $request->input('events', []);

        foreach ($events as $event) {
            $eventId = $this->getEventId($event);

            // Check if already processed
            if ($this->isDuplicate($eventId)) {
                Log::info('Duplicate webhook event skipped', [
                    'event_id' => $eventId,
                    'bot_id' => $bot->id,
                ]);
                continue;
            }

            // Mark as processing
            $this->markProcessing($eventId);

            // Process event
            ProcessLINEWebhook::dispatch($bot, $event, $eventId);
        }

        return response('OK', 200);
    }

    private function getEventId(array $event): string
    {
        // LINE provides webhookEventId
        if (isset($event['webhookEventId'])) {
            return $event['webhookEventId'];
        }

        // Fallback: hash of event content
        return hash('sha256', json_encode($event));
    }

    private function isDuplicate(string $eventId): bool
    {
        return Cache::has("webhook_processed:{$eventId}");
    }

    private function markProcessing(string $eventId): void
    {
        // Mark for 24 hours
        Cache::put("webhook_processed:{$eventId}", true, now()->addDay());
    }
}

// Database-level idempotency for critical operations
class ProcessLINEWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Use database constraint for idempotency
        try {
            Message::create([
                'external_id' => $this->eventId,  // Unique constraint
                'content' => $this->event['message']['text'],
                // ...
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Already processed - skip silently
            Log::debug('Duplicate message skipped', ['event_id' => $this->eventId]);
            return;
        }

        // Continue processing...
    }
}
```

**Why it's secure:**
- Timestamp validation
- Event ID tracking
- Database-level idempotency
- Duplicate detection logged

## Audit Command

```bash
# Check for idempotency handling
grep -rn "duplicate\|idempoten\|webhookEventId" app/ --include="*.php"

# Check for timestamp validation
grep -rn "timestamp\|MAX_AGE" app/Http/Middleware/ --include="*.php"

# Check for unique constraints
grep -rn "external_id\|message_id" database/migrations/ --include="*.php"
```

## Project-Specific Notes

**BotFacebook Replay Prevention:**

```php
// Message model with unique external_id
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->string('external_id')->nullable()->unique();  // LINE/Telegram message ID
    $table->foreignId('conversation_id');
    $table->text('content');
    // ...
});

// ProcessLINEWebhook job
class ProcessLINEWebhook implements ShouldQueue
{
    public int $tries = 1;  // Don't retry on duplicate
    public int $maxExceptions = 1;

    public function handle(): void
    {
        $messageId = $this->event['message']['id'] ?? null;

        if ($messageId) {
            // Check if already exists
            if (Message::where('external_id', $messageId)->exists()) {
                return;  // Skip duplicate
            }
        }

        // Process message...
    }
}

// Cache-based dedup for non-message events (follow, unfollow, etc.)
class WebhookService
{
    public function processEvent(array $event, Bot $bot): void
    {
        $eventId = $event['webhookEventId'] ?? hash('sha256', json_encode($event));

        if (!Cache::add("webhook:{$eventId}", true, now()->addHours(24))) {
            // Already processed
            return;
        }

        // Process...
    }
}
```
