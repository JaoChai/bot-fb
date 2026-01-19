---
id: queue-004-retry-strategy
title: Queue Retry Strategy
impact: MEDIUM
impactDescription: "Failed jobs not retried or retried too aggressively"
category: queue
tags: [queue, retry, backoff, resilience]
relatedRules: [queue-001-failed-jobs, queue-002-job-timeout]
---

## Symptom

- Jobs fail once and never retry
- Jobs retry too fast, overwhelming services
- Same error repeated in failed_jobs
- Temporary failures becoming permanent

## Root Cause

1. No retry configuration
2. Wrong backoff strategy
3. Retrying non-retryable errors
4. Max attempts too low/high
5. No distinction between error types

## Diagnosis

### Quick Check

```bash
# Check retry configuration in job
grep -r "tries\|backoff\|retryUntil" app/Jobs/

# Check failed jobs retry count
php artisan queue:failed | head -20
```

### Detailed Analysis

```sql
-- Analyze retry patterns
SELECT
    payload->>'displayName' as job_name,
    (payload->>'attempts')::int as attempts,
    (payload->>'maxTries')::int as max_tries,
    COUNT(*) as count
FROM failed_jobs
GROUP BY job_name, attempts, max_tries
ORDER BY count DESC;
```

## Solution

### Fix Steps

1. **Configure Retry with Backoff**
```php
class ProcessIncomingMessage implements ShouldQueue
{
    // Number of retry attempts
    public int $tries = 3;

    // Exponential backoff in seconds
    public array $backoff = [10, 30, 60];

    // Or calculate dynamically
    public function backoff(): array
    {
        return [
            10,  // First retry after 10s
            30,  // Second retry after 30s
            60,  // Third retry after 60s
        ];
    }
}
```

2. **Use Different Strategies for Different Errors**
```php
public function handle(): void
{
    try {
        $this->process();
    } catch (RateLimitException $e) {
        // Rate limited - longer backoff
        $this->release($e->retryAfter ?? 60);
    } catch (TemporaryFailureException $e) {
        // Temporary - quick retry
        $this->release(10);
    } catch (PermanentFailureException $e) {
        // Don't retry - fail immediately
        $this->fail($e);
    }
}
```

3. **Configure Time-Based Retry**
```php
class ProcessIncomingMessage implements ShouldQueue
{
    // Keep retrying for 5 minutes
    public function retryUntil(): DateTime
    {
        return now()->addMinutes(5);
    }

    // With calculated backoff
    public function backoff(): int
    {
        // Exponential backoff based on attempt number
        return min(60 * pow(2, $this->attempts()), 300);
    }
}
```

### Code Example

```php
// Good: Smart retry strategy based on error type
namespace App\Jobs;

use App\Exceptions\RateLimitException;
use App\Exceptions\AIServiceException;
use App\Exceptions\InvalidInputException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessage implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3; // Max non-timeout exceptions

    // Backoff in seconds: 10s, 30s, 60s, 120s, 300s
    public array $backoff = [10, 30, 60, 120, 300];

    public function handle(): void
    {
        try {
            $this->processMessage();
        } catch (RateLimitException $e) {
            // Rate limit - use retry-after header or default
            $retryAfter = $e->getRetryAfter() ?? 60;
            Log::warning('Rate limited, retrying', [
                'attempt' => $this->attempts(),
                'retry_after' => $retryAfter,
            ]);
            $this->release($retryAfter);
        } catch (AIServiceException $e) {
            // AI service error - exponential backoff
            if ($this->attempts() < $this->tries) {
                $backoff = $this->backoff[$this->attempts() - 1] ?? 300;
                Log::warning('AI error, retrying', [
                    'attempt' => $this->attempts(),
                    'backoff' => $backoff,
                    'error' => $e->getMessage(),
                ]);
                $this->release($backoff);
            } else {
                $this->fail($e);
            }
        } catch (InvalidInputException $e) {
            // Invalid input - don't retry
            Log::error('Invalid input, failing permanently', [
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        } catch (\Exception $e) {
            // Unknown error - let default retry handle it
            Log::error('Unexpected error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Job failed after all retries', [
            'attempts' => $this->attempts(),
            'exception' => $exception->getMessage(),
        ]);

        // Notify about permanent failure
        event(new MessageProcessingFailed($this->message, $exception));
    }

    // Custom retry decision
    public function shouldRetry(\Throwable $exception): bool
    {
        // Don't retry certain exceptions
        return !($exception instanceof InvalidInputException
            || $exception instanceof AuthenticationException);
    }
}
```

## Prevention

- Define clear retry strategy per job type
- Distinguish between retryable and permanent errors
- Use exponential backoff
- Set reasonable max retry attempts
- Monitor retry patterns

## Debug Commands

```bash
# Check job retry configuration
php artisan tinker
>>> $job = new ProcessIncomingMessage($message);
>>> [$job->tries, $job->backoff];

# Simulate retry
php artisan queue:retry {job_id}

# Watch retry behavior
php artisan queue:work --once -v

# Clear specific failed jobs
php artisan queue:forget {job_id}
```

## Project-Specific Notes

**BotFacebook Context:**
- Webhook jobs: 3 retries, exponential backoff
- AI jobs: 5 retries, longer backoff (AI can be slow)
- Non-retryable: `InvalidPlatformEventException`
- Rate limit handling uses `Retry-After` header
