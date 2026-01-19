---
id: job-004-chaining
title: Job Chaining
impact: MEDIUM
impactDescription: "Enables sequential job processing with proper error handling"
category: job
tags: [job, queue, chain, batch]
relatedRules: [job-001-retry-configuration, job-003-dispatching]
---

## Why This Matters

Job chaining ensures jobs run sequentially when order matters. If one job in the chain fails, subsequent jobs don't run. This prevents partial processing and data inconsistency.

## Bad Example

```php
// Problem: Independent dispatches - no order guarantee
DownloadDocument::dispatch($url);
ProcessDocument::dispatch($documentId);
IndexDocument::dispatch($documentId);
// ProcessDocument might run before DownloadDocument finishes!
```

**Why it's wrong:**
- No execution order
- Race conditions
- Partial processing
- Hard to track completion

## Good Example

```php
use Illuminate\Support\Facades\Bus;

// Job chain - sequential execution
Bus::chain([
    new DownloadDocument($url),
    new ProcessDocument($documentId),
    new IndexDocument($documentId),
])
->onQueue('documents')
->dispatch();

// With completion callbacks
Bus::chain([
    new DownloadDocument($url),
    new ProcessDocument($documentId),
    new IndexDocument($documentId),
])
->then(function () use ($documentId) {
    // All jobs completed successfully
    Document::find($documentId)->update(['status' => 'indexed']);
    NotifyUser::dispatch($userId, 'Document processed');
})
->catch(function (\Throwable $e) use ($documentId) {
    // A job in the chain failed
    Document::find($documentId)->update(['status' => 'failed']);
    Log::error('Document processing failed', ['error' => $e->getMessage()]);
})
->finally(function () use ($documentId) {
    // Always runs
    Cache::forget("document:{$documentId}:processing");
})
->onQueue('documents')
->dispatch();

// Job batches (parallel + tracking)
$batch = Bus::batch([
    new ProcessChunk($chunk1),
    new ProcessChunk($chunk2),
    new ProcessChunk($chunk3),
])
->then(fn(Batch $batch) => Document::find($id)->update(['status' => 'complete']))
->catch(fn(Batch $batch, \Throwable $e) => Log::error('Batch failed'))
->allowFailures()
->onQueue('processing')
->dispatch();

// Track batch progress
$batch = Bus::findBatch($batchId);
$batch->progress(); // 0-100
$batch->finished(); // bool
$batch->cancelled(); // bool
```

**Why it's better:**
- Guaranteed order
- Atomic processing
- Completion tracking
- Proper error handling

## Project-Specific Notes

**BotFacebook Chain Patterns:**

```php
// Knowledge base indexing
Bus::chain([
    new ParseDocument($document),
    new ChunkDocument($document->id),
    new GenerateEmbeddings($document->id),
    new IndexChunks($document->id),
])
->then(fn() => $document->update(['status' => 'indexed']))
->catch(fn($e) => $document->update(['status' => 'failed', 'error' => $e->getMessage()]))
->onQueue('embeddings')
->dispatch();

// Message processing
Bus::chain([
    new ProcessIncomingMessage($message),
    new GenerateResponse($message->conversation_id),
    new SendResponse($message->conversation_id),
])
->onQueue('messages')
->dispatch();
```

**Batch Table Migration:**
```bash
php artisan queue:batches-table
php artisan migrate
```

## References

- [Laravel Job Chaining](https://laravel.com/docs/queues#job-chaining)
- [Job Batching](https://laravel.com/docs/queues#job-batching)
