---
id: queue-002-job-timeout
title: Job Timeout Errors
impact: HIGH
impactDescription: "Long-running jobs killed, incomplete processing"
category: queue
tags: [queue, timeout, performance, long-running]
relatedRules: [queue-001-failed-jobs, queue-003-worker-config]
---

## Symptom

- Job starts but never completes
- "MaxAttemptsExceededException" errors
- Worker process killed
- Partial processing (message received but no reply)

## Root Cause

1. Job timeout too short for operation
2. AI response taking too long
3. External API slow/unresponsive
4. Database queries blocking
5. Memory exhaustion causing slowdown

## Diagnosis

### Quick Check

```bash
# Check current timeout settings
grep -r "timeout" app/Jobs/

# Monitor job execution time
php artisan queue:work -v 2>&1 | tee queue.log

# Check for timeout-related failures
grep -i "timeout" storage/logs/laravel.log | tail -20
```

### Detailed Analysis

```sql
-- Check job execution times from failed jobs
SELECT
    payload->>'displayName' as job_name,
    (payload->>'timeout')::int as timeout_seconds,
    failed_at,
    CASE
        WHEN exception LIKE '%timeout%' THEN 'TIMEOUT'
        WHEN exception LIKE '%MaxAttempts%' THEN 'MAX_ATTEMPTS'
        ELSE 'OTHER'
    END as failure_type
FROM failed_jobs
WHERE failed_at > NOW() - INTERVAL '24 hours'
ORDER BY failed_at DESC;
```

## Solution

### Fix Steps

1. **Increase Job Timeout**
```php
class ProcessIncomingMessage implements ShouldQueue
{
    // Timeout in seconds
    public int $timeout = 120; // 2 minutes

    // Or use retryUntil for time-based
    public function retryUntil(): DateTime
    {
        return now()->addMinutes(5);
    }
}
```

2. **Configure Worker Timeout**
```bash
# Worker timeout must be > job timeout
php artisan queue:work --timeout=180 --tries=3
```

3. **Optimize Long Operations**
```php
// Break into smaller jobs
class ProcessIncomingMessage implements ShouldQueue
{
    public function handle(): void
    {
        // Quick initial processing
        $validated = $this->validateMessage();

        // Dispatch AI processing to separate job
        dispatch(new GenerateAIResponse($validated));

        // Quick acknowledgment
        $this->sendTypingIndicator();
    }
}

class GenerateAIResponse implements ShouldQueue
{
    public int $timeout = 300; // 5 minutes for AI
}
```

### Code Example

```php
// Good: Timeout-aware job with progress tracking
namespace App\Jobs;

use App\Models\Message;
use App\Services\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProcessIncomingMessage implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        public Message $message
    ) {}

    public function handle(OpenRouterService $openRouter): void
    {
        $startTime = microtime(true);

        try {
            // Track progress for monitoring
            $this->updateProgress('started');

            // Set per-operation timeouts
            $response = $openRouter->generateWithTimeout(
                $this->message->content,
                timeout: 90 // Leave buffer for other operations
            );

            $this->updateProgress('ai_complete');

            // Save response
            $this->saveResponse($response);

            $duration = microtime(true) - $startTime;
            Log::info('Job completed', [
                'message_id' => $this->message->id,
                'duration' => round($duration, 2),
            ]);

        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            Log::warning('Job error', [
                'message_id' => $this->message->id,
                'duration' => round($duration, 2),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function updateProgress(string $stage): void
    {
        Cache::put(
            "job_progress:{$this->message->id}",
            [
                'stage' => $stage,
                'timestamp' => now()->toISOString(),
            ],
            now()->addMinutes(30)
        );
    }

    public function failed(\Throwable $exception): void
    {
        $this->updateProgress('failed');

        // Check if it was a timeout
        if (str_contains($exception->getMessage(), 'timeout')) {
            Log::error('Job timed out', [
                'message_id' => $this->message->id,
                'timeout' => $this->timeout,
            ]);

            // Maybe retry with longer timeout
            dispatch(new self($this->message))
                ->delay(now()->addSeconds(30));
        }
    }
}
```

## Prevention

- Set realistic timeouts based on actual operation times
- Monitor job execution duration
- Use streaming for long AI responses
- Break large jobs into smaller chunks
- Add operation-level timeouts

## Debug Commands

```bash
# Profile job execution time
php artisan tinker
>>> $start = microtime(true);
>>> dispatch_sync(new ProcessIncomingMessage($message));
>>> echo "Took: " . (microtime(true) - $start) . "s";

# Check worker processes
ps aux | grep queue:work

# Monitor queue worker
watch -n 1 'php artisan queue:monitor default'

# Find slow jobs in logs
grep "duration" storage/logs/laravel.log | awk '{if ($NF > 60) print}'
```

## Project-Specific Notes

**BotFacebook Context:**
- Default timeout: 120 seconds
- AI generation jobs: 300 seconds
- Worker command: `php artisan queue:work --timeout=180`
- Progress tracked in Redis with `job_progress:{id}` keys
