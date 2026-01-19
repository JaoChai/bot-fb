---
id: job-001-retry-configuration
title: Job Retry Configuration
impact: CRITICAL
impactDescription: "Prevents job failures from causing data loss and ensures reliable async processing"
category: job
tags: [job, queue, retry, failure]
relatedRules: [job-002-failed-handling, laravel-003-service-layer]
---

## Why This Matters

Queue jobs can fail due to temporary issues (network timeouts, API rate limits, database locks). Without proper retry configuration, these failures become permanent data loss. Proper configuration ensures transient failures are handled gracefully.

## Bad Example

```php
// Problem: No retry configuration
class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Message $message) {}

    public function handle(): void
    {
        // If this fails, job is lost forever!
        $this->processMessage();
    }
}
```

**Why it's wrong:**
- No retry attempts defined
- No backoff between retries
- No failure handling
- Transient failures cause permanent loss
- No timeout protection

## Good Example

```php
// Solution: Proper retry configuration
class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying.
     * Array = exponential backoff: 5s, 30s, 60s
     */
    public array $backoff = [5, 30, 60];

    /**
     * Maximum seconds the job can run.
     */
    public int $timeout = 120;

    /**
     * Unique job - prevent duplicates in queue.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public Message $message
    ) {}

    /**
     * Determine the unique ID for this job.
     */
    public function uniqueId(): string
    {
        return $this->message->id;
    }

    public function handle(MessageProcessor $processor): void
    {
        $processor->process($this->message);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Message processing failed permanently', [
            'message_id' => $this->message->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Update message status
        $this->message->update(['status' => 'failed']);

        // Notify monitoring (Sentry)
        report($exception);
    }
}
```

**Why it's better:**
- 3 retry attempts with increasing delays
- Exponential backoff prevents API hammering
- Timeout prevents stuck jobs
- Unique job prevents duplicates
- Failed handler for cleanup and alerting

## Project-Specific Notes

**BotFacebook Job Configurations:**

```php
// ProcessLINEWebhook - Webhook processing
public int $tries = 3;
public array $backoff = [5, 30, 60];
public int $timeout = 60;

// GenerateEmbedding - AI processing (can be slow)
public int $tries = 2;
public array $backoff = [10, 60];
public int $timeout = 300; // 5 minutes for large documents

// SendPlatformMessage - External API calls
public int $tries = 3;
public array $backoff = [5, 15, 30];
public int $timeout = 30;
```

**Queue Worker Configuration:**
```bash
# Production worker with memory limits
php artisan queue:work --tries=3 --backoff=5,30,60 --memory=256 --timeout=120
```

**Monitor Failed Jobs:**
```bash
# List failed jobs
php artisan queue:failed

# Retry specific job
php artisan queue:retry {id}

# Retry all
php artisan queue:retry all
```

## References

- [Laravel Queues](https://laravel.com/docs/queues)
- [Job Middleware](https://laravel.com/docs/queues#job-middleware)
