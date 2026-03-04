<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Contextual Retrieval Service
 *
 * Implements Anthropic's Contextual Retrieval technique to improve RAG accuracy.
 * Generates context for document chunks before embedding to reduce retrieval failures.
 *
 * How it works:
 * 1. Generate a summary of the full document
 * 2. For each chunk, generate a brief context explaining what it contains
 * 3. Embed the concatenation of context + chunk content
 *
 * Research shows: ~49% reduction in retrieval failure rate
 *
 * @see https://www.anthropic.com/news/contextual-retrieval
 */
class ContextualRetrievalService
{
    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Check if contextual retrieval is enabled.
     */
    public function isEnabled(): bool
    {
        return config('rag.contextual_retrieval.enabled', true);
    }

    /**
     * Generate a summary of the document for context generation.
     *
     * @param  string  $documentTitle  The document title/filename
     * @param  string  $documentContent  The full document content
     * @param  string|null  $apiKey  Optional API key override
     * @return array{summary: string, tokens_used: int}
     */
    public function generateDocumentSummary(
        string $documentTitle,
        string $documentContent,
        ?string $apiKey = null
    ): array {
        $model = config('rag.contextual_retrieval.model', 'openai/gpt-4o-mini');
        $maxTokens = config('rag.contextual_retrieval.max_summary_tokens', 200);

        // Truncate content if too long (keep first ~8000 chars for summary)
        $truncatedContent = mb_substr($documentContent, 0, 8000);
        if (mb_strlen($documentContent) > 8000) {
            $truncatedContent .= "\n\n[... content truncated ...]";
        }

        $prompt = <<<PROMPT
You are a document analyzer. Create a brief summary (2-4 sentences) of this document that captures:
- What the document is about
- Key topics or entities mentioned
- The type of content (e.g., product catalog, FAQ, policy document)

Document Title: {$documentTitle}

Document Content:
---
{$truncatedContent}
---

Write ONLY the summary, no introduction or explanation.
PROMPT;

        try {
            $result = $this->openRouter->chat(
                messages: [
                    ['role' => 'user', 'content' => $prompt],
                ],
                model: $model,
                temperature: 0.3,
                maxTokens: $maxTokens,
                useFallback: false,
                apiKeyOverride: $apiKey
            );

            return [
                'summary' => trim($result['content']),
                'tokens_used' => $result['usage']['total_tokens'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to generate document summary', [
                'document_title' => $documentTitle,
                'error' => $e->getMessage(),
            ]);

            // Fallback: use first 200 chars as summary
            return [
                'summary' => mb_substr($documentContent, 0, 200).'...',
                'tokens_used' => 0,
            ];
        }
    }

    /**
     * Generate context for a batch of chunks.
     *
     * @param  string  $documentTitle  The document title
     * @param  string  $documentSummary  The document summary
     * @param  array  $chunks  Array of chunk contents
     * @param  string|null  $apiKey  Optional API key override
     * @return array{contexts: array<string>, tokens_used: int}
     */
    public function generateChunkContexts(
        string $documentTitle,
        string $documentSummary,
        array $chunks,
        ?string $apiKey = null
    ): array {
        $model = config('rag.contextual_retrieval.model', 'openai/gpt-4o-mini');
        $maxTokens = config('rag.contextual_retrieval.max_context_tokens', 100);

        $contexts = [];
        $totalTokens = 0;

        // Process chunks in batches
        $batchSize = config('rag.contextual_retrieval.batch_size', 5);
        $batches = array_chunk($chunks, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $batchContexts = $this->generateBatchContexts(
                    $documentTitle,
                    $documentSummary,
                    $batch,
                    $model,
                    $maxTokens,
                    $apiKey
                );

                $contexts = array_merge($contexts, $batchContexts['contexts']);
                $totalTokens += $batchContexts['tokens_used'];
            } catch (\Exception $e) {
                Log::warning('Failed to generate chunk contexts for batch', [
                    'document_title' => $documentTitle,
                    'batch_index' => $batchIndex,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage(),
                ]);

                // Fallback: generate simple contexts
                foreach ($batch as $chunk) {
                    $contexts[] = $this->generateFallbackContext($documentTitle, $chunk);
                }
            }
        }

        return [
            'contexts' => $contexts,
            'tokens_used' => $totalTokens,
        ];
    }

    /**
     * Generate contexts for a batch of chunks in a single LLM call.
     */
    protected function generateBatchContexts(
        string $documentTitle,
        string $documentSummary,
        array $chunks,
        string $model,
        int $maxTokensPerContext,
        ?string $apiKey
    ): array {
        $chunksText = '';
        foreach ($chunks as $index => $chunk) {
            $chunkPreview = mb_substr($chunk, 0, 500);
            $chunksText .= "CHUNK {$index}:\n{$chunkPreview}\n\n";
        }

        $prompt = <<<PROMPT
Document: {$documentTitle}
Summary: {$documentSummary}

For each chunk below, write a brief context (1-2 sentences) that explains:
- What this chunk discusses
- How it relates to the document

{$chunksText}

Respond with ONLY the contexts in this exact format (one per line):
CONTEXT 0: [context for chunk 0]
CONTEXT 1: [context for chunk 1]
...
PROMPT;

        $result = $this->openRouter->chat(
            messages: [
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $model,
            temperature: 0.3,
            maxTokens: $maxTokensPerContext * count($chunks) + 50,
            useFallback: false,
            apiKeyOverride: $apiKey
        );

        // Parse the response
        $contexts = $this->parseContextsResponse($result['content'], count($chunks));

        return [
            'contexts' => $contexts,
            'tokens_used' => $result['usage']['total_tokens'] ?? 0,
        ];
    }

    /**
     * Parse the LLM response to extract individual contexts.
     */
    protected function parseContextsResponse(string $response, int $expectedCount): array
    {
        $contexts = [];
        $lines = explode("\n", $response);

        foreach ($lines as $line) {
            $line = trim($line);
            // Match "CONTEXT N:" pattern
            if (preg_match('/^CONTEXT\s*\d+\s*:\s*(.+)$/i', $line, $matches)) {
                $contexts[] = trim($matches[1]);
            }
        }

        // If parsing failed, try to split by numbered patterns
        if (count($contexts) < $expectedCount) {
            $contexts = [];
            if (preg_match_all('/(?:CONTEXT\s*\d+\s*:|^\d+[\.\)]\s*)(.+?)(?=(?:CONTEXT\s*\d+|^\d+[\.\)]|$))/ims', $response, $matches)) {
                foreach ($matches[1] as $match) {
                    $context = trim($match);
                    if (! empty($context)) {
                        $contexts[] = $context;
                    }
                }
            }
        }

        // Pad with empty strings if still not enough
        while (count($contexts) < $expectedCount) {
            $contexts[] = '';
        }

        // Truncate if too many
        return array_slice($contexts, 0, $expectedCount);
    }

    /**
     * Generate a simple fallback context when LLM fails.
     */
    protected function generateFallbackContext(string $documentTitle, string $chunkContent): string
    {
        // Extract first sentence or first 100 chars
        $firstSentence = preg_match('/^[^.!?]+[.!?]/', $chunkContent, $matches)
            ? $matches[0]
            : mb_substr($chunkContent, 0, 100).'...';

        return "From document '{$documentTitle}': {$firstSentence}";
    }

    /**
     * Generate context for a single chunk.
     *
     * @param  string  $documentTitle  The document title
     * @param  string  $documentSummary  The document summary
     * @param  string  $chunkContent  The chunk content
     * @param  string|null  $apiKey  Optional API key override
     * @return array{context: string, tokens_used: int}
     */
    public function generateSingleChunkContext(
        string $documentTitle,
        string $documentSummary,
        string $chunkContent,
        ?string $apiKey = null
    ): array {
        $model = config('rag.contextual_retrieval.model', 'openai/gpt-4o-mini');
        $maxTokens = config('rag.contextual_retrieval.max_context_tokens', 100);

        $chunkPreview = mb_substr($chunkContent, 0, 500);

        $prompt = <<<PROMPT
Document: {$documentTitle}
Summary: {$documentSummary}

Chunk:
---
{$chunkPreview}
---

Write a brief context (1-2 sentences) explaining what this chunk discusses and how it relates to the document.
Write ONLY the context, no introduction.
PROMPT;

        try {
            $result = $this->openRouter->chat(
                messages: [
                    ['role' => 'user', 'content' => $prompt],
                ],
                model: $model,
                temperature: 0.3,
                maxTokens: $maxTokens,
                useFallback: false,
                apiKeyOverride: $apiKey
            );

            return [
                'context' => trim($result['content']),
                'tokens_used' => $result['usage']['total_tokens'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to generate single chunk context', [
                'document_title' => $documentTitle,
                'error' => $e->getMessage(),
            ]);

            return [
                'context' => $this->generateFallbackContext($documentTitle, $chunkContent),
                'tokens_used' => 0,
            ];
        }
    }

    /**
     * Combine context and content for embedding.
     *
     * @param  string  $context  The generated context
     * @param  string  $content  The original chunk content
     * @return string The combined text for embedding
     */
    public function combineForEmbedding(string $context, string $content): string
    {
        if (empty($context)) {
            return $content;
        }

        return "[Context: {$context}]\n\n{$content}";
    }
}
