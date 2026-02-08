<?php

namespace App\Jobs;

use App\Events\DocumentStatusUpdated;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\ChunkingService;
use App\Services\ContextualRetrievalService;
use App\Services\DocumentParserService;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessDocument implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 300;

    public function __construct(
        public Document $document,
        public ?int $userId = null
    ) {}

    public function handle(
        DocumentParserService $parser,
        ChunkingService $chunker,
        ContextualRetrievalService $contextualRetrieval
    ): void {
        Log::info('Processing document', [
            'document_id' => $this->document->id,
            'filename' => $this->document->original_filename,
        ]);

        try {
            $this->document->update(['status' => 'processing']);
            broadcast(new DocumentStatusUpdated($this->document, 'processing'));

            // Check file size limit to prevent OOM
            $maxFileSize = config('rag.max_document_size', 50 * 1024 * 1024); // 50MB
            if ($this->document->file_size && $this->document->file_size > $maxFileSize) {
                throw new \RuntimeException(
                    "Document too large: " . number_format($this->document->file_size / 1024 / 1024, 1) . "MB exceeds limit of " . number_format($maxFileSize / 1024 / 1024, 1) . "MB"
                );
            }

            // Get user's API key from their settings
            $apiKey = $this->getUserApiKey();
            $embedder = new EmbeddingService($apiKey);

            // Use content directly for text-only documents, parse file for legacy
            if (!empty($this->document->content)) {
                $text = $this->document->content;
            } elseif ($this->document->storage_path) {
                $text = $parser->parse(
                    $this->document->storage_path,
                    $this->document->mime_type
                );
            } else {
                throw new \RuntimeException('Document has no content or file');
            }

            if (empty($text)) {
                throw new \RuntimeException('Document contains no extractable text');
            }

            $chunks = $chunker->chunk($text);

            if (empty($chunks)) {
                throw new \RuntimeException('Document produced no chunks');
            }

            Log::info('Document parsed', [
                'document_id' => $this->document->id,
                'text_length' => strlen($text),
                'chunk_count' => count($chunks),
            ]);

            // Generate contextual information if enabled
            $documentSummary = '';
            $chunkContexts = [];

            if ($contextualRetrieval->isEnabled()) {
                $documentTitle = $this->document->original_filename ?? $this->document->filename ?? 'Untitled';

                // Step 1: Generate document summary
                $summaryResult = $contextualRetrieval->generateDocumentSummary(
                    $documentTitle,
                    $text,
                    $apiKey
                );
                $documentSummary = $summaryResult['summary'];

                Log::debug('Document summary generated', [
                    'document_id' => $this->document->id,
                    'summary_length' => strlen($documentSummary),
                    'tokens_used' => $summaryResult['tokens_used'],
                ]);

                // Step 2: Generate chunk contexts
                $chunkContents = array_column($chunks, 'content');
                $contextsResult = $contextualRetrieval->generateChunkContexts(
                    $documentTitle,
                    $documentSummary,
                    $chunkContents,
                    $apiKey
                );
                $chunkContexts = $contextsResult['contexts'];

                Log::debug('Chunk contexts generated', [
                    'document_id' => $this->document->id,
                    'contexts_count' => count($chunkContexts),
                    'tokens_used' => $contextsResult['tokens_used'],
                ]);
            }

            $this->processChunksWithEmbeddings($chunks, $embedder, $contextualRetrieval, $chunkContexts);

            $this->document->update([
                'status' => 'completed',
                'chunk_count' => count($chunks),
                'error_message' => null,
            ]);
            broadcast(new DocumentStatusUpdated($this->document->fresh(), 'completed'));

            $this->document->knowledgeBase->increment('chunk_count', count($chunks));

            Log::info('Document processing completed', [
                'document_id' => $this->document->id,
                'chunks_created' => count($chunks),
                'contextual_retrieval' => $contextualRetrieval->isEnabled(),
            ]);
        } catch (Throwable $e) {
            Log::error('Document processing failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);

            $this->document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            broadcast(new DocumentStatusUpdated($this->document->fresh(), 'failed'));

            throw $e;
        }
    }

    protected function processChunksWithEmbeddings(
        array $chunks,
        EmbeddingService $embedder,
        ContextualRetrievalService $contextualRetrieval,
        array $chunkContexts = []
    ): void {
        $batchSize = 20;
        $batches = array_chunk($chunks, $batchSize, true);
        $contextBatches = array_chunk($chunkContexts, $batchSize, true);
        $useContextualRetrieval = $contextualRetrieval->isEnabled() && !empty($chunkContexts);
        $globalIndex = 0;

        foreach ($batches as $batchIndex => $batch) {
            // Add throttle between batches (not before first)
            if ($batchIndex > 0) {
                usleep(50_000); // 50ms delay to prevent rate limiting
            }

            // Prepare texts for embedding
            // If contextual retrieval is enabled, combine context + content
            $texts = [];
            $batchContexts = $contextBatches[$batchIndex] ?? [];

            foreach ($batch as $chunkIndex => $chunk) {
                $context = $batchContexts[$chunkIndex] ?? '';

                if ($useContextualRetrieval && !empty($context)) {
                    // Combine context and content for embedding
                    $texts[] = $contextualRetrieval->combineForEmbedding($context, $chunk['content']);
                } else {
                    $texts[] = $chunk['content'];
                }
            }

            $embeddings = $embedder->generateBatch($texts);

            DB::transaction(function () use ($batch, $embeddings, $batchContexts, $useContextualRetrieval, &$globalIndex) {
                foreach ($batch as $localIndex => $chunk) {
                    $context = $useContextualRetrieval ? ($batchContexts[$localIndex] ?? null) : null;

                    DocumentChunk::create([
                        'document_id' => $this->document->id,
                        'content' => $chunk['content'],
                        'context_text' => $context,
                        'chunk_index' => $chunk['chunk_index'],
                        'start_char' => $chunk['start_char'],
                        'end_char' => $chunk['end_char'],
                        'embedding' => $embeddings[$localIndex] ?? null,
                        'metadata' => [
                            'word_count' => $chunk['word_count'] ?? null,
                            'has_context' => !empty($context),
                        ],
                    ]);

                    $globalIndex++;
                }
            });

            Log::debug('Processed batch', [
                'document_id' => $this->document->id,
                'batch' => $batchIndex + 1,
                'chunks' => count($batch),
                'contextual_retrieval' => $useContextualRetrieval,
            ]);
        }
    }

    /**
     * Get the API key for embedding generation.
     * Priority: 1) Specified user's key, 2) Document owner's key, 3) null (falls back to env)
     */
    protected function getUserApiKey(): ?string
    {
        // Try specified user first (using safe getter to handle decryption errors)
        if ($this->userId) {
            $user = User::find($this->userId);
            $apiKey = $user?->settings?->getOpenRouterApiKey();
            if ($apiKey) {
                return $apiKey;
            }
        }

        // Fallback to document owner's API key
        $owner = $this->document->knowledgeBase?->user;
        return $owner?->settings?->getOpenRouterApiKey();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessDocument job failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        $this->document->update([
            'status' => 'failed',
            'error_message' => 'Processing failed after retries: ' . $exception->getMessage(),
        ]);
        broadcast(new DocumentStatusUpdated($this->document->fresh(), 'failed'));
    }
}
