---
id: event-003-listener-queuing
title: Listener Queuing
impact: MEDIUM
impactDescription: "Prevents slow listeners from blocking response times"
category: event
tags: [event, listener, queue, async]
relatedRules: [event-001-dispatching, job-001-retry-configuration]
---

## Why This Matters

Queued listeners run asynchronously, preventing slow operations from blocking HTTP responses. Without queuing, sending an email or calling an API in a listener delays the user's response.

## Bad Example

```php
// Problem: Sync listener blocks response
class SendWelcomeEmail
{
    public function handle(UserRegistered $event): void
    {
        // This takes 2-5 seconds!
        Mail::to($event->user)->send(new WelcomeMail($event->user));
    }
}

// User waits 5 seconds for registration to complete
```

**Why it's wrong:**
- Slow response times
- Poor user experience
- Timeout risks
- Single point of failure

## Good Example

```php
// Queued listener - runs async
class SendWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Queue connection.
     */
    public $connection = 'redis';

    /**
     * Queue name.
     */
    public $queue = 'emails';

    /**
     * Delay in seconds.
     */
    public $delay = 10;

    /**
     * Number of retries.
     */
    public $tries = 3;

    /**
     * Backoff between retries.
     */
    public $backoff = [10, 60, 300];

    public function handle(UserRegistered $event): void
    {
        Mail::to($event->user)->send(new WelcomeMail($event->user));
    }

    /**
     * Handle failure.
     */
    public function failed(UserRegistered $event, \Throwable $exception): void
    {
        Log::error('Welcome email failed', [
            'user_id' => $event->user->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Determine if should queue.
     */
    public function shouldQueue(UserRegistered $event): bool
    {
        return $event->user->email_verified_at !== null;
    }
}
```

**Why it's better:**
- Instant response to user
- Failures don't affect main flow
- Retry on failure
- Conditional queuing

## Project-Specific Notes

**BotFacebook Listener Patterns:**

```php
// Always queue slow operations
class ProcessWebhook implements ShouldQueue
{
    public $queue = 'webhooks';
}

class GenerateEmbeddings implements ShouldQueue
{
    public $queue = 'ai';
    public $tries = 2;
    public $timeout = 300; // 5 minutes
}

// Sync for critical logging
class LogSecurityEvent // No ShouldQueue = sync
{
    public function handle(SecurityEvent $event): void
    {
        SecurityLog::create([...]); // Must complete
    }
}
```

**Queue vs Sync Decision:**
| Operation | Queue? | Why |
|-----------|--------|-----|
| Send email | Yes | Slow, can retry |
| Write log | No | Fast, critical |
| Call external API | Yes | Unreliable |
| Update cache | No | Fast |
| Generate embedding | Yes | Very slow |

## References

- [Laravel Queued Event Listeners](https://laravel.com/docs/events#queued-event-listeners)
