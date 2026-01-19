---
id: flow-003-error-handling
title: Proper Error Handling in Flow
impact: MEDIUM
impactDescription: "Errors cause silent failures or poor user experience"
category: flow
tags: [error-handling, exceptions, fallback, resilience]
relatedRules: [queue-001-failed-jobs, flow-001-message-tracing]
---

## Symptom

- User gets no response when errors occur
- Silent failures without logging
- Inconsistent error messages
- Partial processing without cleanup

## Root Cause

- Missing try-catch blocks
- Swallowing exceptions without logging
- No fallback mechanisms
- No user-facing error messages

## Diagnosis

### Quick Check

```bash
# Check for unhandled exceptions
grep -r "Exception" storage/logs/laravel.log | grep -v "caught" | tail -20

# Check error rate
grep "ERROR" storage/logs/laravel.log | wc -l
```

### Detailed Analysis

```sql
-- Check message failure rate
SELECT
    DATE(created_at) as date,
    COUNT(*) FILTER (WHERE status = 'failed') as failed,
    COUNT(*) FILTER (WHERE status = 'completed') as completed,
    ROUND(
        COUNT(*) FILTER (WHERE status = 'failed')::numeric /
        NULLIF(COUNT(*), 0) * 100, 2
    ) as failure_rate
FROM messages
WHERE created_at > NOW() - INTERVAL '7 days'
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

## Solution

### Fix Steps

1. **Catch and Handle Appropriately**
```php
public function handle(): void
{
    try {
        $response = $this->generateResponse();
        $this->sendReply($response);
    } catch (AIServiceException $e) {
        // Log and send fallback
        Log::error('AI service failed', ['error' => $e->getMessage()]);
        $this->sendFallbackResponse('ขออภัย ระบบมีปัญหาชั่วคราว กรุณาลองใหม่');
    } catch (PlatformAPIException $e) {
        // Log but can't reply (platform is down)
        Log::error('Platform API failed', ['error' => $e->getMessage()]);
        $this->markForRetry();
    } catch (\Exception $e) {
        // Unexpected error - log full trace
        Log::error('Unexpected error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        report($e); // Send to Sentry
        $this->sendFallbackResponse('เกิดข้อผิดพลาด กรุณาติดต่อผู้ดูแลระบบ');
    }
}
```

2. **Create Error Response Service**
```php
class ErrorResponseService
{
    private array $responses = [
        'ai_unavailable' => 'ขออภัย ระบบ AI ไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ในอีกสักครู่',
        'rate_limited' => 'ขออภัย ระบบได้รับข้อความจำนวนมาก กรุณารอสักครู่',
        'invalid_input' => 'ขออภัย ไม่สามารถประมวลผลข้อความนี้ได้',
        'generic' => 'ขออภัย เกิดข้อผิดพลาด กรุณาลองใหม่',
    ];

    public function getResponse(string $type): string
    {
        return $this->responses[$type] ?? $this->responses['generic'];
    }
}
```

3. **Implement Circuit Breaker**
```php
class CircuitBreaker
{
    public function call(string $service, callable $operation)
    {
        if ($this->isOpen($service)) {
            throw new CircuitOpenException("Circuit open for {$service}");
        }

        try {
            $result = $operation();
            $this->recordSuccess($service);
            return $result;
        } catch (\Exception $e) {
            $this->recordFailure($service);

            if ($this->shouldOpen($service)) {
                $this->open($service);
            }

            throw $e;
        }
    }
}
```

### Code Example

```php
// Good: Comprehensive error handling
namespace App\Jobs;

use App\Models\Message;
use App\Services\{RAGService, ErrorResponseService, CircuitBreakerService};
use App\Exceptions\{AIServiceException, PlatformAPIException, RateLimitException};
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessage implements ShouldQueue
{
    public function __construct(
        public Message $message
    ) {}

    public function handle(
        RAGService $rag,
        ErrorResponseService $errorResponse,
        CircuitBreakerService $circuitBreaker
    ): void {
        try {
            // Check circuit breaker first
            $response = $circuitBreaker->call('ai_service', function () use ($rag) {
                return $rag->generateResponse($this->message);
            });

            $this->sendReply($response);
            $this->markSuccess();

        } catch (CircuitOpenException $e) {
            // Service is known to be down
            Log::warning('Circuit open', ['service' => 'ai_service']);
            $this->sendFallbackAndRetryLater($errorResponse->getResponse('ai_unavailable'));

        } catch (RateLimitException $e) {
            // Rate limited - queue for later
            Log::info('Rate limited, scheduling retry', [
                'retry_after' => $e->getRetryAfter(),
            ]);
            $this->release($e->getRetryAfter());

        } catch (AIServiceException $e) {
            // AI error - send fallback
            Log::error('AI service error', [
                'error' => $e->getMessage(),
                'model' => $e->getModel(),
            ]);
            $this->sendFallbackResponse($errorResponse->getResponse('ai_unavailable'));
            $this->markFailed('ai_error', $e->getMessage());

        } catch (PlatformAPIException $e) {
            // Can't send reply - will need to retry
            Log::error('Platform API error', [
                'platform' => $this->message->bot->platform,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed('platform_error', $e->getMessage());
            throw $e; // Retry via queue

        } catch (\Exception $e) {
            // Unexpected error
            Log::error('Unexpected error in message processing', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e); // Sentry

            // Try to notify user
            try {
                $this->sendFallbackResponse($errorResponse->getResponse('generic'));
            } catch (\Exception $replyError) {
                Log::error('Could not send error message', [
                    'error' => $replyError->getMessage(),
                ]);
            }

            $this->markFailed('unexpected', $e->getMessage());
        }
    }

    private function sendFallbackAndRetryLater(string $message): void
    {
        $this->sendFallbackResponse($message);
        $this->release(60); // Retry in 1 minute
    }

    private function markSuccess(): void
    {
        $this->message->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    private function markFailed(string $reason, string $details): void
    {
        $this->message->update([
            'status' => 'failed',
            'error_reason' => $reason,
            'error_details' => $details,
        ]);
    }

    private function sendFallbackResponse(string $content): void
    {
        // Implementation depends on platform
        $this->message->bot->sendMessage(
            $this->message->platform_user_id,
            $content
        );
    }
}
```

## Prevention

- Catch specific exceptions
- Always log errors with context
- Provide user-friendly fallback messages
- Use circuit breakers for external services
- Monitor error rates

## Debug Commands

```bash
# Check error distribution
grep "ERROR\|Exception" storage/logs/laravel.log | \
  awk '{print $NF}' | sort | uniq -c | sort -rn | head -10

# Check message failure reasons
php artisan tinker
>>> Message::where('status', 'failed')
...   ->selectRaw('error_reason, COUNT(*)')
...   ->groupBy('error_reason')
...   ->get();

# Test error handling
php artisan test --filter ErrorHandling
```

## Project-Specific Notes

**BotFacebook Context:**
- Fallback messages in `config/bot-messages.php`
- Circuit breaker for OpenRouter API
- Error tracking via Sentry
- User-facing errors in Thai language
