---
id: job-002-failed-handling
title: Failed Job Handling
impact: HIGH
impactDescription: "Ensures failed jobs are logged, notified, and recoverable"
category: job
tags: [job, queue, failure, monitoring]
relatedRules: [job-001-retry-configuration, job-003-dispatching]
---

## Why This Matters

Jobs can fail permanently after all retries. Without proper failure handling, you lose visibility into problems and can't recover data. Failed jobs should be logged, trigger alerts, and update related records.

## Bad Example

```php
// Problem: No failure handling
class ProcessMessage implements ShouldQueue
{
    public function handle(): void
    {
        // If this fails after all retries, no one knows!
        $this->processMessage();
    }
}
```

**Why it's wrong:**
- Silent failure
- No logging
- No alerting
- Data stuck in limbo
- Hard to debug

## Good Example

```php
// Solution: Comprehensive failure handling
class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 30, 60];

    public function __construct(
        public Message $message
    ) {}

    public function handle(MessageProcessor $processor): void
    {
        $processor->process($this->message);

        // Update status on success
        $this->message->update(['status' => 'processed']);
    }

    /**
     * Handle permanent job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // 1. Log detailed error
        Log::error('Message processing failed permanently', [
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // 2. Update record status
        $this->message->update([
            'status' => 'failed',
            'error_message' => Str::limit($exception->getMessage(), 500),
        ]);

        // 3. Report to monitoring (Sentry)
        report($exception);

        // 4. Optional: Notify admin
        Notification::send(
            User::admins()->get(),
            new JobFailedNotification($this->message, $exception)
        );
    }

    /**
     * Handle retryable exception differently.
     */
    public function retryUntil(): \DateTime
    {
        // Keep retrying for 1 hour
        return now()->addHour();
    }
}
```

**Why it's better:**
- Failures logged with context
- Record status updated
- Monitoring alerted
- Optional admin notification
- Recoverable data

## Project-Specific Notes

**BotFacebook Failed Job Patterns:**

```php
// Common failed() implementation
public function failed(\Throwable $exception): void
{
    // Update related model
    $this->model->update([
        'status' => 'failed',
        'error_message' => $exception->getMessage(),
        'failed_at' => now(),
    ]);

    // Report to Sentry with context
    \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($exception) {
        $scope->setContext('job', [
            'class' => static::class,
            'model_id' => $this->model->id,
        ]);
        \Sentry\captureException($exception);
    });
}
```

**Monitor Failed Jobs:**
```bash
# List failed jobs
php artisan queue:failed

# Retry specific job
php artisan queue:retry {id}

# Retry all failed
php artisan queue:retry all

# Clear old failed jobs
php artisan queue:flush
```

**Dashboard Query:**
```sql
SELECT * FROM failed_jobs
WHERE failed_at > NOW() - INTERVAL '24 hours'
ORDER BY failed_at DESC;
```

## References

- [Laravel Failed Jobs](https://laravel.com/docs/queues#dealing-with-failed-jobs)
- [Job Events](https://laravel.com/docs/queues#job-events)
