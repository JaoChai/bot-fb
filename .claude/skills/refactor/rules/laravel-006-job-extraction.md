---
id: laravel-006-job-extraction
title: Extract Job Refactoring
impact: HIGH
impactDescription: "Move long-running tasks from sync to async job processing"
category: laravel
tags: [job, queue, async, performance]
relatedRules: [laravel-002-extract-service, laravel-001-extract-method]
---

## Code Smell

- HTTP request takes > 5 seconds
- User waits for background task
- Same heavy operation in multiple places
- Timeout errors on API endpoints

## Root Cause

1. Started as quick sync operation
2. Grew over time
3. No queue infrastructure initially
4. Fear of job complexity
5. Unclear async boundaries

## When to Apply

**Apply when:**
- Operation > 2 seconds
- User doesn't need immediate result
- Operation can fail/retry
- External API calls involved
- Batch processing needed

**Don't apply when:**
- User needs immediate result
- Operation is fast (< 1 second)
- Would add unnecessary complexity

## Solution

### Before

```php
class BotController extends Controller
{
    public function processDocument(Request $request, Bot $bot)
    {
        $file = $request->file('document');

        // Extract text (slow)
        $text = $this->extractText($file);

        // Generate embeddings (slow - API call)
        $embeddings = $this->embeddingService->generate($text);

        // Store in vector database (slow)
        foreach ($embeddings as $chunk) {
            KnowledgeBaseDocument::create([
                'bot_id' => $bot->id,
                'content' => $chunk['text'],
                'embedding' => $chunk['vector'],
            ]);
        }

        // User waits 30+ seconds
        return response()->json(['status' => 'completed']);
    }
}
```

### After

```php
// app/Http/Controllers/Api/BotController.php
class BotController extends Controller
{
    public function processDocument(Request $request, Bot $bot)
    {
        $file = $request->file('document');

        // Store file for processing
        $path = $file->store('documents');

        // Dispatch job (returns immediately)
        ProcessDocumentJob::dispatch($bot, $path)
            ->onQueue('documents');

        // User gets immediate response
        return response()->json([
            'status' => 'processing',
            'message' => 'Document is being processed',
        ], 202);
    }
}

// app/Jobs/ProcessDocumentJob.php
class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [30, 60, 120];

    public function __construct(
        public Bot $bot,
        public string $filePath
    ) {}

    public function handle(
        TextExtractor $extractor,
        EmbeddingService $embeddingService
    ): void {
        // Extract text
        $text = $extractor->extract(Storage::path($this->filePath));

        // Generate embeddings
        $embeddings = $embeddingService->generate($text);

        // Store in database
        DB::transaction(function () use ($embeddings) {
            foreach ($embeddings as $chunk) {
                KnowledgeBaseDocument::create([
                    'bot_id' => $this->bot->id,
                    'content' => $chunk['text'],
                    'embedding' => $chunk['vector'],
                ]);
            }
        });

        // Notify user
        $this->bot->user->notify(new DocumentProcessed($this->bot));

        // Cleanup
        Storage::delete($this->filePath);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Document processing failed', [
            'bot_id' => $this->bot->id,
            'file' => $this->filePath,
            'error' => $exception->getMessage(),
        ]);

        $this->bot->user->notify(new DocumentProcessingFailed($this->bot));
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(1);
    }
}
```

### Step-by-Step

1. **Create job class**
   ```bash
   php artisan make:job ProcessDocumentJob
   ```

2. **Move logic to handle()**
   - Copy slow operations
   - Inject dependencies
   - Add error handling

3. **Add job configuration**
   ```php
   public int $tries = 3;
   public int $timeout = 300;
   public array $backoff = [30, 60, 120];
   ```

4. **Update controller**
   - Dispatch job
   - Return 202 Accepted
   - Add status tracking if needed

5. **Add failed() handler**
   - Log failures
   - Notify users
   - Cleanup resources

## Verification

```bash
# Test job dispatches
php artisan test --filter ProcessDocumentJobTest

# Test job works in queue
php artisan queue:work --once

# Monitor queue
php artisan queue:monitor
```

## Anti-Patterns

- **Not serializing properly**: Use SerializesModels
- **Too much in one job**: Break into multiple jobs
- **No retry logic**: Always configure retries
- **Missing failed handler**: Always handle failures

## Project-Specific Notes

**BotFacebook Context:**
- Jobs location: `app/Jobs/`
- Queue: Redis via Railway
- Key jobs: ProcessLINEWebhook, ProcessDocument
- Timeout: 5 min default
- Notify user on complete/fail
