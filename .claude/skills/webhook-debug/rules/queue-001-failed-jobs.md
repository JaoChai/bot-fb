---
id: queue-001-failed-jobs
title: Jobs Failing in Queue
impact: CRITICAL
impactDescription: "Messages not processed, bot appears dead"
category: queue
tags: [queue, jobs, failed, debugging]
relatedRules: [queue-002-job-timeout, queue-003-worker-config]
---

## Symptom

- Webhook received but no response sent
- Messages stuck in "processing" state
- `failed_jobs` table growing
- Bot intermittently responds

## Root Cause

1. Unhandled exception in job
2. External service timeout (AI, platform API)
3. Database connection lost
4. Memory limit exceeded
5. Serialization/deserialization errors

## Diagnosis

### Quick Check

```bash
# List failed jobs
php artisan queue:failed

# Check recent failures
php artisan queue:failed --json | jq '.[-5:]'

# Check failed job details
php artisan queue:failed 12345
```

### Detailed Analysis

```sql
-- Check failed_jobs table directly
SELECT
    id,
    queue,
    payload->>'displayName' as job_name,
    exception,
    failed_at
FROM failed_jobs
ORDER BY failed_at DESC
LIMIT 10;

-- Count failures by job type
SELECT
    payload->>'displayName' as job_name,
    COUNT(*) as failure_count
FROM failed_jobs
WHERE failed_at > NOW() - INTERVAL '24 hours'
GROUP BY payload->>'displayName'
ORDER BY failure_count DESC;
```

## Solution

### Fix Steps

1. **Identify the Error**
```bash
# Get full exception from failed job
php artisan queue:failed {job_id}

# Or from database
SELECT exception FROM failed_jobs WHERE id = {job_id};
```

2. **Fix the Issue**
```php
// Add error handling to job
public function handle(): void
{
    try {
        $this->processMessage();
    } catch (OpenRouterException $e) {
        // Retry with backoff
        $this->release(60); // Retry in 60 seconds
    } catch (PlatformRateLimitException $e) {
        // Longer backoff for rate limits
        $this->release(300);
    } catch (\Exception $e) {
        // Log and fail
        Log::error('Job failed', [
            'job' => static::class,
            'error' => $e->getMessage(),
        ]);
        $this->fail($e);
    }
}
```

3. **Retry Failed Jobs**
```bash
# Retry single job
php artisan queue:retry {job_id}

# Retry all failed jobs
php artisan queue:retry all

# Retry jobs from specific queue
php artisan queue:retry --queue=webhooks
```

### Code Example

```php
// Good: Robust job with proper error handling
namespace App\Jobs;

use App\Models\Message;
use App\Services\RAGService;
use App\Exceptions\AIServiceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public Message $message
    ) {}

    public function handle(RAGService $ragService): void
    {
        Log::info('Processing message', ['message_id' => $this->message->id]);

        try {
            $response = $ragService->generateResponse(
                $this->message->content,
                $this->message->conversation
            );

            $this->message->conversation->messages()->create([
                'content' => $response,
                'role' => 'assistant',
            ]);

            Log::info('Message processed', ['message_id' => $this->message->id]);
        } catch (AIServiceException $e) {
            Log::warning('AI service error, retrying', [
                'message_id' => $this->message->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e; // Will use backoff
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Message processing failed permanently', [
            'message_id' => $this->message->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Update message status
        $this->message->update(['status' => 'failed']);

        // Notify admin
        event(new JobFailed($this->message, $exception));
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }
}
```

## Prevention

- Add proper error handling in all jobs
- Use appropriate retry strategies
- Monitor failed_jobs table
- Set up alerts for job failures
- Test jobs with edge cases

## Debug Commands

```bash
# Watch job processing
php artisan queue:work -v

# Process single job and see output
php artisan queue:work --once -v

# Clear old failed jobs
php artisan queue:flush

# Prune old failed jobs (keep 7 days)
php artisan queue:prune-failed --hours=168

# Check queue health
php artisan queue:monitor default,webhooks,broadcasts
```

## Project-Specific Notes

**BotFacebook Context:**
- Main queues: `default`, `webhooks`, `broadcasts`
- Critical jobs: `ProcessLINEWebhook`, `ProcessTelegramWebhook`
- `JobFailed` event notifies via Slack
- Max retries: 3 with exponential backoff
