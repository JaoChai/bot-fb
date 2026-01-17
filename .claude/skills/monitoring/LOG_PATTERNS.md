# Log Patterns Guide

## Structured Logging

### Laravel Log Format

```php
// Good - structured log
Log::info('Message processed', [
    'bot_id' => $bot->id,
    'message_id' => $message->id,
    'platform' => $bot->platform,
    'duration_ms' => $duration,
]);

// Bad - string concatenation
Log::info("Message {$message->id} processed for bot {$bot->id}");
```

### Log Levels

| Level | Use For |
|-------|---------|
| `emergency` | System unusable |
| `alert` | Immediate action needed |
| `critical` | Critical conditions |
| `error` | Runtime errors |
| `warning` | Warning conditions |
| `notice` | Normal but significant |
| `info` | Informational messages |
| `debug` | Debug information |

## Common Log Patterns

### API Request/Response

```php
// Request received
Log::info('api.request', [
    'method' => $request->method(),
    'path' => $request->path(),
    'user_id' => auth()->id(),
    'ip' => $request->ip(),
]);

// Response sent
Log::info('api.response', [
    'path' => $request->path(),
    'status' => $response->status(),
    'duration_ms' => $duration,
]);
```

### Job Processing

```php
// Job started
Log::info('job.started', [
    'job' => class_basename($this),
    'id' => $this->job->uuid(),
    'queue' => $this->queue,
]);

// Job completed
Log::info('job.completed', [
    'job' => class_basename($this),
    'id' => $this->job->uuid(),
    'duration_ms' => $duration,
]);

// Job failed
Log::error('job.failed', [
    'job' => class_basename($this),
    'id' => $this->job->uuid(),
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString(),
]);
```

### External API Calls

```php
// API call
Log::info('external.request', [
    'service' => 'openrouter',
    'endpoint' => '/chat/completions',
    'model' => $model,
]);

// API response
Log::info('external.response', [
    'service' => 'openrouter',
    'status' => $response->status(),
    'tokens' => $response->json('usage.total_tokens'),
    'duration_ms' => $duration,
]);
```

### Authentication Events

```php
// Login success
Log::info('auth.login', [
    'user_id' => $user->id,
    'ip' => $request->ip(),
    'user_agent' => $request->userAgent(),
]);

// Login failed
Log::warning('auth.login_failed', [
    'email' => $request->email,
    'ip' => $request->ip(),
    'reason' => 'invalid_credentials',
]);

// Logout
Log::info('auth.logout', [
    'user_id' => auth()->id(),
]);
```

### Bot Events

```php
// Message received
Log::info('bot.message_received', [
    'bot_id' => $bot->id,
    'platform' => $bot->platform,
    'message_type' => $messageType,
]);

// AI response
Log::info('bot.ai_response', [
    'bot_id' => $bot->id,
    'model' => $model,
    'tokens' => $tokens,
    'duration_ms' => $duration,
]);

// Reply sent
Log::info('bot.reply_sent', [
    'bot_id' => $bot->id,
    'platform' => $bot->platform,
    'message_id' => $messageId,
]);
```

## Searching Logs in Railway

### Filter by Level

```bash
# Errors only
railway logs --filter "@level:error"

# Warnings and errors
railway logs --filter "@level:warning OR @level:error"
```

### Filter by Content

```bash
# Find specific bot
railway logs --filter "bot_id:123"

# Find job failures
railway logs --filter "job.failed"

# Find slow queries
railway logs --filter "duration_ms:>1000"
```

### Combine Filters

```bash
# Errors for specific bot
railway logs --filter "@level:error AND bot_id:123"
```

## Log Retention

| Environment | Retention | Location |
|-------------|-----------|----------|
| Local | 14 days | `storage/logs/` |
| Production | 30 days | Railway logs |
| Errors | 90 days | Sentry |

## Anti-Patterns

```php
// DON'T log sensitive data
Log::info('User logged in', [
    'password' => $password,  // NEVER
    'token' => $token,        // NEVER
]);

// DON'T log in loops
foreach ($items as $item) {
    Log::info('Processing', ['id' => $item->id]);  // Too much
}

// DO log summary instead
Log::info('Batch processed', [
    'count' => count($items),
    'duration_ms' => $duration,
]);
```
