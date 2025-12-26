<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\ChunkingService;
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
        public Document $document
    ) {}

    public function handle(
        DocumentParserService $parser,
        ChunkingService $chunker,
        EmbeddingService $embedder
    ): void {
        Log::info('Processing document', [
            'document_id' => $this->document->id,
            'filename' => $this->document->original_filename,
        ]);

        try {
            $this->document->update(['status' => 'processing']);

            $text = $parser->parse(
                $this->document->storage_path,
                $this->document->mime_type
            );

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

            $this->processChunksWithEmbeddings($chunks, $embedder);

            $this->document->update([
                'status' => 'completed',
                'chunk_count' => count($chunks),
                'error_message' => null,
            ]);

            $this->document->knowledgeBase->increment('chunk_count', count($chunks));

            Log::info('Document processing completed', [
                'document_id' => $this->document->id,
                'chunks_created' => count($chunks),
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

            throw $e;
        }
    }

    protected function processChunksWithEmbeddings(array $chunks, EmbeddingService $embedder): void
    {
        $batchSize = 20;
        $batches = array_chunk($chunks, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $texts = array_column($batch, 'content');
            $embeddings = $embedder->generateBatch($texts);

            DB::transaction(function () use ($batch, $embeddings) {
                foreach ($batch as $index => $chunk) {
                    DocumentChunk::create([
                        'document_id' => $this->document->id,
                        'content' => $chunk['content'],
                        'chunk_index' => $chunk['chunk_index'],
                        'start_char' => $chunk['start_char'],
                        'end_char' => $chunk['end_char'],
                        'embedding' => $embeddings[$index] ?? null,
                        'metadata' => [
                            'word_count' => $chunk['word_count'] ?? null,
                        ],
                    ]);
                }
            });

            Log::debug('Processed batch', [
                'document_id' => $this->document->id,
                'batch' => $batchIndex + 1,
                'chunks' => count($batch),
            ]);
        }
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
    }
}
