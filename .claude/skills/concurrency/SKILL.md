# Concurrency & Race Condition Patterns

Locking strategies, duplicate prevention, transaction optimization for bot-fb.

## Decision Tree

```
Race Condition Type?
│
├─ Database State (conversation, profile, message)
│  ├─ Read + Write → lockForUpdate() in DB::transaction
│  └─ Unique Violation → Try-catch UniqueConstraintViolationException + retry
│
├─ Cache/Memory State (aggregation, response lock)
│  ├─ Cross-job coordination → Cache::lock with block timeout
│  └─ Simple counter/flag → Cache::increment (atomic)
│
├─ Long-Held Lock (API call inside transaction)
│  └─ Move API calls OUTSIDE DB::transaction
│
├─ Webhook Deduplication
│  ├─ Primary → webhook_event_id (LINE best practice)
│  └─ Fallback → external_message_id
│
├─ Duplicate AI Response
│  └─ Response cache lock ("ai_response:{conversation_id}")
│
├─ Service Dependency Failing
│  └─ CircuitBreakerService (3-state: closed/open/half-open)
│
└─ External API Rate Limited
   └─ HTTP retry with linear backoff (200ms, 400ms, 600ms)
```

## Patterns in Project

### 1. Pessimistic Locking (lockForUpdate)

```php
// ProcessLINEWebhook.php - Prevent duplicate conversations
DB::transaction(function () {
    $conversation = Conversation::where('bot_id', $this->bot->id)
        ->where('external_customer_id', $userId)
        ->lockForUpdate()  // SELECT...FOR UPDATE
        ->first();

    if (!$conversation) {
        $conversation = $this->createNewConversation();
    }

    $userMessage = Message::create([...]);
});
```

**Use when**: Multiple jobs may create same record simultaneously.

### 2. Response Cache Lock

```php
// Prevent duplicate AI responses for same conversation
$responseLock = Cache::lock("ai_response:{$conversation->id}", 30);

if (!$responseLock->get()) {
    // Another job already generating → use aggregation fallback
    $aggregationService->startOrContinueAggregation(...);
} else {
    // Generate response
    try {
        $botMessage = $aiService->generateAndSaveResponse(...);
    } finally {
        // Lock auto-expires after 30s
    }
}
```

**Use when**: Multiple webhook events trigger AI generation for same conversation.

### 3. Message Aggregation (Cache::lock)

```php
// Coordinate across concurrent webhook jobs
$lock = Cache::lock("msg_agg_lock:{$conversationId}", 5);
$lock->block(3);  // Wait up to 3 seconds

$existingGroupId = Cache::get($groupKey);
if (!$existingGroupId) {
    Cache::put($groupKey, $groupId, $ttl);
} else {
    // Timer reset: new UUID invalidates old delayed jobs
    $groupId = Str::uuid();
    Cache::put($groupKey, $groupId, $ttl);
}
```

**Use when**: Multiple messages arrive rapidly, need to batch before AI response.

### 4. Unique Constraint + Catch

```php
// Handle race in customer profile creation
try {
    return CustomerProfile::create([
        'external_id' => $userId,
        'channel_type' => 'line', ...
    ]);
} catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
    return CustomerProfile::where('external_id', $userId)
        ->where('channel_type', 'line')
        ->first();
}
```

**Use when**: INSERT may conflict with concurrent INSERT of same unique key.

### 5. Split Transactions (Performance)

```php
// BAD: Lock held for 3+ seconds (API call inside transaction)
DB::transaction(function () {
    $conversation = Conversation::lockForUpdate()->first();
    $response = $aiService->generate(...);  // 2-3s API call!
    Message::create(['content' => $response]);
});

// GOOD: Lock held for ~50ms only
DB::transaction(function () {
    $conversation = Conversation::lockForUpdate()->first();
    $userMessage = Message::create([...]);
});
// API call OUTSIDE transaction
$response = $aiService->generate(...);
// Atomic update (no transaction needed)
DB::raw("UPDATE conversations SET message_count = message_count + 1 ...");
```

### 6. Circuit Breaker (3-State)

```php
// CircuitBreakerService.php
// CLOSED → normal, OPEN → blocked, HALF_OPEN → testing recovery
$breaker = new CircuitBreakerService('openrouter', [
    'failure_threshold' => 5,     // Failures before opening
    'recovery_timeout' => 30,     // Seconds before half-open
    'success_threshold' => 2,     // Successes to close from half-open
]);
```

### 7. WebSocket Dedup (X-Socket-ID)

```php
// Frontend: sends socket ID with API requests
config.headers['X-Socket-ID'] = getSocketId();

// Backend: excludes sender from broadcast
broadcast(new MessageSent($message))->toOthers();
```

**Use when**: Frontend sends message AND listens for broadcast of same message.

## Unique Constraints in Project

| Table | Constraint | Purpose |
|-------|-----------|---------|
| customer_profiles | (external_id, channel_type) | Per-platform unique |
| messages | webhook_event_id (app-level check) | Webhook dedup (no DB constraint) |
| messages | (conversation_id, external_message_id) | Message dedup |
| quick_replies | (user_id, shortcut) | Unique shortcuts |
| bot_settings | bot_id | One settings per bot |
| flow_knowledge_base | (flow_id, knowledge_base_id) | No duplicate KB |

## Key Files

| File | Pattern |
|------|---------|
| `app/Jobs/ProcessLINEWebhook.php` | lockForUpdate + dedup + response lock |
| `app/Jobs/ProcessTelegramWebhook.php` | Simpler: dedup + broadcast, no lockForUpdate |
| `app/Services/MessageAggregationService.php` | Cache::lock + timer reset |
| `app/Services/CircuitBreakerService.php` | 3-state circuit breaker |
| `app/Services/OpenRouterService.php` | HTTP retry with backoff |
| `app/Http/Controllers/Api/FlowController.php` | lockForUpdate for setDefault |
| `app/Services/Chat/TagService.php` | Bulk tag with lockForUpdate |

### 8. SmartAggregation Sub-system

Builds on top of `MessageAggregationService` with intelligent message grouping:

| File | Purpose |
|------|---------|
| `app/Services/SmartAggregation/SmartAggregationAnalyzer.php` | Intelligent message grouping decisions |
| `app/Services/SmartAggregation/AggregationContext.php` | Aggregation state tracking |
| `app/Services/SmartAggregation/ThaiLanguagePatterns.php` | Thai language-specific handling |
| `app/Services/SmartAggregation/UserTypingStats.php` | Typing pattern analysis for wait-time tuning |

**Use when**: Fine-tuning message batching behavior, especially for Thai language conversations where splitting patterns differ from English.

## Critical Gotchas

| Gotcha | Detail |
|--------|--------|
| Lock duration | Keep transactions < 100ms, move API calls outside |
| Null vs empty | `hitl_dangerous_actions: null` = defaults, `[]` = allow all |
| Retry recovery | If webhook retries and user msg exists but no bot response → retry AI |
| Octane state | Reset all metrics at request start (no cross-request leaks) |
| Cache driver | Must use Redis/Memcached for Cache::lock (file driver won't work) |
| Partial index | Dedup indexes use WHERE...IS NOT NULL (only index user messages) |

## Quick Commands

```bash
# Check for lockForUpdate usage
grep -rn "lockForUpdate" backend/app/ --include="*.php"

# Check for DB::transaction usage
grep -rn "DB::transaction" backend/app/ --include="*.php"

# Check unique constraints
grep -rn "unique\|UniqueConstraint" backend/database/migrations/ --include="*.php"
```
