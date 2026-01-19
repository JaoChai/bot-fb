---
id: job-003-dispatching
title: Job Dispatching Patterns
impact: HIGH
impactDescription: "Ensures reliable job queuing with proper queue selection and delays"
category: job
tags: [job, queue, dispatch, async]
relatedRules: [job-001-retry-configuration, job-002-failed-handling]
---

## Why This Matters

Proper job dispatching ensures jobs go to the right queue, run at the right time, and don't overwhelm the system. Wrong queue selection or missing delays can cause performance issues or incorrect behavior.

## Bad Example

```php
// Problem: Always sync processing
$this->processMessage($message); // Blocks request, slow response

// Problem: Wrong queue for heavy work
HeavyProcessingJob::dispatch($data); // Goes to default queue
// Blocks quick jobs in default queue

// Problem: Missing delay for rate-limited APIs
SendMessage::dispatch($message);
SendMessage::dispatch($message2);
SendMessage::dispatch($message3);
// All hit API at once, rate limited!
```

**Why it's wrong:**
- Sync processing blocks requests
- Heavy jobs block queue
- No rate limiting protection
- Poor user experience

## Good Example

```php
// Solution 1: Basic dispatch to specific queue
ProcessMessage::dispatch($message)
    ->onQueue('messages');

// Solution 2: Delayed dispatch
SendNotification::dispatch($user)
    ->delay(now()->addMinutes(5));

// Solution 3: Conditional dispatch
if ($shouldProcess) {
    ProcessDocument::dispatchIf($condition, $document);
}

// Solution 4: Chain related jobs
Bus::chain([
    new DownloadDocument($url),
    new ProcessDocument($documentId),
    new IndexDocument($documentId),
])->onQueue('documents')->dispatch();

// Solution 5: Batch processing with status
$batch = Bus::batch([
    new ProcessChunk($chunk1),
    new ProcessChunk($chunk2),
    new ProcessChunk($chunk3),
])
->then(fn(Batch $batch) => Log::info('All processed'))
->catch(fn(Batch $batch, \Throwable $e) => Log::error('Batch failed'))
->finally(fn(Batch $batch) => NotifyUser::dispatch($user))
->onQueue('chunks')
->dispatch();

// Solution 6: Rate-limited dispatching
foreach ($messages as $index => $message) {
    SendMessage::dispatch($message)
        ->delay(now()->addSeconds($index * 2)); // 2 second gap
}
```

**Why it's better:**
- Async processing = fast response
- Queue separation = fair scheduling
- Delays = rate limit compliance
- Chains = ordered processing
- Batches = group tracking

## Project-Specific Notes

**BotFacebook Queue Configuration:**

```php
// config/queue.php - Queue definitions
'queues' => [
    'default',      // General tasks
    'webhooks',     // Webhook processing (high priority)
    'messages',     // Message sending
    'ai',           // AI processing (heavy, separate worker)
    'embeddings',   // Vector generation (CPU intensive)
]
```

**Job Queue Assignments:**
```php
// Webhook jobs - high priority
class ProcessLINEWebhook implements ShouldQueue
{
    public $queue = 'webhooks';
}

// AI processing - separate worker
class GenerateResponse implements ShouldQueue
{
    public $queue = 'ai';
}

// Dynamic queue based on priority
SendMessage::dispatch($message)
    ->onQueue($urgent ? 'high' : 'messages');
```

**Worker Commands:**
```bash
# Run specific queue
php artisan queue:work --queue=webhooks,messages,default

# Multiple workers with memory limit
php artisan queue:work --queue=ai --memory=512 --timeout=300
```

## References

- [Laravel Job Dispatching](https://laravel.com/docs/queues#dispatching-jobs)
- [Job Chaining](https://laravel.com/docs/queues#job-chaining)
- [Job Batching](https://laravel.com/docs/queues#job-batching)
