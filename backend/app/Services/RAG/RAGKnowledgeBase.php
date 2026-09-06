<?php

namespace App\Services\RAG;

use App\Models\Bot;
use App\Models\Flow;
use App\Services\CRAGService;
use App\Services\FlowCacheService;
use App\Services\HybridSearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Knowledge-base retrieval, formatting and CRAG split out of RAGService
 * (Track 3). Bodies are moved verbatim.
 */
class RAGKnowledgeBase
{
    public function __construct(
        private readonly HybridSearchService $hybridSearchService,
        private readonly FlowCacheService $flowCacheService,
        private readonly ?CRAGService $cragService = null,
    ) {}

    /**
     * Check if the bot should use its Knowledge Base.
     *
     * Uses Flow-level KB attachment as source of truth (consistent with StreamController).
     * If the default flow has KBs attached, they will be used regardless of bot.kb_enabled.
     */
    public function shouldUseKnowledgeBase(Bot $bot): bool
    {
        $defaultFlow = $this->flowCacheService->getDefaultFlow($bot->id);

        if (! $defaultFlow || ! $defaultFlow->knowledgeBases()->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Get context from Knowledge Base for the given query.
     *
     * Delegates to getFlowKnowledgeBaseContext since KBs are now accessed via Flow.
     */
    public function getKnowledgeBaseContext(
        Bot $bot,
        string $query,
        array &$metadata
    ): string {
        // Get default flow and delegate to flow-based context retrieval
        $defaultFlow = $this->flowCacheService->getDefaultFlow($bot->id);
        if (! $defaultFlow) {
            return '';
        }

        return $this->getFlowKnowledgeBaseContext($defaultFlow, $query, $metadata);
    }

    /**
     * Format KB search results into context for the prompt.
     * Public method to allow delegation from controllers.
     */
    public function formatKnowledgeBaseContext($results): string
    {
        if ($results->isEmpty()) {
            return '';
        }

        $template = config('rag.context_template', 'thai');

        if ($template === 'thai') {
            return $this->formatThaiContext($results);
        }

        return $this->formatEnglishContext($results);
    }

    /**
     * Format context in Thai.
     */
    protected function formatThaiContext($results): string
    {
        $context = "## ข้อมูลอ้างอิงจาก Knowledge Base:\n\n";

        foreach ($results as $i => $result) {
            $relevance = round($result['similarity'] * 100);
            $context .= '### แหล่งที่ '.($i + 1)." (ความเกี่ยวข้อง {$relevance}%)\n";
            $context .= "📄 {$result['document_name']}\n\n";
            $context .= $result['content']."\n\n";
        }

        $context .= "---\n";
        $context .= '📌 **คำแนะนำ**: ใช้ข้อมูลด้านบนในการตอบคำถาม ';
        $context .= "หากข้อมูลไม่เกี่ยวข้องหรือไม่เพียงพอ ให้ตอบตามความรู้ทั่วไปและแจ้งผู้ใช้ว่าไม่พบข้อมูลในระบบ\n";

        return $context;
    }

    /**
     * Format context in English.
     */
    protected function formatEnglishContext($results): string
    {
        $context = "## Reference Information from Knowledge Base:\n\n";

        foreach ($results as $i => $result) {
            $relevance = round($result['similarity'] * 100);
            $context .= '### Source '.($i + 1)." (Relevance: {$relevance}%)\n";
            $context .= "Document: {$result['document_name']}\n\n";
            $context .= $result['content']."\n\n";
        }

        $context .= "---\n";
        $context .= "**Instructions**: Use the information above to answer the user's question. ";
        $context .= "If the information is not relevant or insufficient, respond using general knowledge and inform the user.\n";

        return $context;
    }

    /**
     * Get the API key to use for a bot.
     *
     * Priority:
     * 1. User's API key from Settings page
     * 2. Config/env fallback
     */
    public function getApiKeyForBot(Bot $bot): ?string
    {
        return $bot->user?->settings?->getOpenRouterApiKey()
            ?? config('services.openrouter.api_key');
    }

    /**
     * Get context from a Flow's Knowledge Bases (Many-to-Many).
     * Searches all attached KBs using hybrid search and merges results.
     */
    public function getFlowKnowledgeBaseContext(
        Flow $flow,
        string $query,
        array &$metadata
    ): string {
        $knowledgeBases = $flow->knowledgeBases;

        if ($knowledgeBases->isEmpty()) {
            return '';
        }

        try {
            // Build KB configs from pivot data
            $kbConfigs = $knowledgeBases->map(fn ($kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'kb_top_k' => $kb->pivot->kb_top_k ?? 5,
                'kb_similarity_threshold' => $kb->pivot->kb_similarity_threshold ?? 0.7,
            ])->toArray();

            // Get API key: User Settings > ENV
            $apiKey = $flow->bot ? $this->getApiKeyForBot($flow->bot) : config('services.openrouter.api_key');

            // Search all KBs using hybrid search and merge results
            $results = $this->hybridSearchService->searchMultiple(
                kbConfigs: $kbConfigs,
                query: $query,
                totalLimit: config('rag.max_results', 5),
                apiKey: $apiKey
            );

            // CRAG: Evaluate retrieval quality and take corrective action
            $results = $this->applyCRAG($results, $query, $kbConfigs, $metadata, $apiKey);

            if ($results->isEmpty()) {
                Log::debug('No relevant results from Flow KBs', [
                    'flow_id' => $flow->id,
                    'kb_count' => count($kbConfigs),
                    'query' => substr($query, 0, 100),
                    'search_mode' => $this->hybridSearchService->isEnabled() ? 'hybrid' : 'semantic',
                ]);

                return '';
            }

            // Update metadata with hybrid search info
            $metadata['enabled'] = true;
            $metadata['results_count'] = $results->count();
            $metadata['kb_count'] = $knowledgeBases->count();
            $metadata['search_mode'] = $this->hybridSearchService->isEnabled() ? 'hybrid' : 'semantic';
            $metadata['chunks_used'] = $results->map(fn ($r) => [
                'document' => $r['document_name'],
                'knowledge_base_id' => $r['knowledge_base_id'],
                'similarity' => $r['similarity'],
                'rrf_score' => $r['rrf_score'] ?? null,
            ])->toArray();

            // Format context for prompt
            return $this->formatKnowledgeBaseContext($results);
        } catch (\Exception $e) {
            Log::error('Flow KB search failed', [
                'flow_id' => $flow->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Check if a Flow has Knowledge Bases attached.
     */
    public function flowHasKnowledgeBases(Flow $flow): bool
    {
        return $flow->knowledgeBases()->exists();
    }

    /**
     * Apply Corrective RAG (CRAG) evaluation to search results.
     *
     * Evaluates retrieval quality and takes corrective action:
     * - "correct" (high similarity): use results directly
     * - "ambiguous" (mid similarity): rewrite query and re-search
     * - "incorrect" (low similarity): return empty (skip KB)
     *
     * Wrapped in try-catch so CRAG failures never break the KB search.
     */
    protected function applyCRAG(
        Collection $results,
        string $query,
        array $kbConfigs,
        array &$metadata,
        ?string $apiKey
    ): Collection {
        if (! $this->cragService?->isEnabled() || $results->isEmpty()) {
            return $results;
        }

        try {
            $evaluation = $this->cragService->evaluate($results, $query);
            $metadata['crag'] = $evaluation;

            Log::debug('CRAG: Evaluation result', [
                'grade' => $evaluation['grade'],
                'top_similarity' => $evaluation['top_similarity'],
            ]);

            if ($evaluation['grade'] === CRAGService::GRADE_INCORRECT) {
                return collect([]);
            }

            if ($evaluation['grade'] === CRAGService::GRADE_AMBIGUOUS) {
                for ($attempt = 0; $attempt < $this->cragService->getMaxRewriteAttempts(); $attempt++) {
                    $rewrittenQuery = $this->cragService->rewriteQuery($query, $results, $apiKey);

                    $newResults = $this->hybridSearchService->searchMultiple(
                        kbConfigs: $kbConfigs,
                        query: $rewrittenQuery,
                        totalLimit: config('rag.max_results', 5),
                        apiKey: $apiKey
                    );

                    if ($newResults->isEmpty()) {
                        continue;
                    }

                    $newEval = $this->cragService->evaluate($newResults, $rewrittenQuery);

                    Log::debug('CRAG: Rewrite attempt result', [
                        'attempt' => $attempt + 1,
                        'rewritten_query' => $rewrittenQuery,
                        'grade' => $newEval['grade'],
                        'top_similarity' => $newEval['top_similarity'],
                    ]);

                    if ($newEval['grade'] === CRAGService::GRADE_CORRECT) {
                        $metadata['crag']['rewrite_attempts'] = $attempt + 1;
                        $metadata['crag']['rewritten_query'] = $rewrittenQuery;

                        return $newResults;
                    }
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::warning('CRAG: Evaluation failed, using original results', [
                'error' => $e->getMessage(),
            ]);

            return $results;
        }
    }
}
