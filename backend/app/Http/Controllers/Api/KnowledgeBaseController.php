<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeBase\StoreKnowledgeBaseRequest;
use App\Http\Requests\KnowledgeBase\UpdateKnowledgeBaseRequest;
use App\Http\Resources\KnowledgeBaseResource;
use App\Models\Bot;
use App\Models\KnowledgeBase;
use App\Services\SemanticSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KnowledgeBaseController extends Controller
{
    protected SemanticSearchService $searchService;

    public function __construct(SemanticSearchService $searchService)
    {
        $this->searchService = $searchService;
    }
    /**
     * Get or create the knowledge base for a bot.
     */
    public function show(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $kb = $bot->knowledgeBase;

        if (!$kb) {
            // Auto-create KB for bot if it doesn't exist
            $kb = KnowledgeBase::create([
                'user_id' => $request->user()->id,
                'bot_id' => $bot->id,
                'name' => $bot->name . ' Knowledge Base',
                'description' => 'Knowledge base for ' . $bot->name,
            ]);
        }

        return response()->json([
            'data' => new KnowledgeBaseResource($kb->load('documents')),
        ]);
    }

    /**
     * Update knowledge base settings.
     */
    public function update(UpdateKnowledgeBaseRequest $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $kb = $bot->knowledgeBase;

        if (!$kb) {
            return response()->json([
                'message' => 'Knowledge base not found',
            ], 404);
        }

        $kb->update($request->validated());

        return response()->json([
            'message' => 'Knowledge base updated successfully',
            'data' => new KnowledgeBaseResource($kb->fresh()),
        ]);
    }

    /**
     * Search knowledge base using semantic similarity.
     */
    public function search(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $validated = $request->validate([
            'query' => 'required|string|min:1|max:1000',
            'limit' => 'integer|min:1|max:20',
            'threshold' => 'numeric|min:0|max:1',
        ]);

        $kb = $bot->knowledgeBase;

        if (!$kb) {
            return response()->json([
                'message' => 'Knowledge base not found',
                'results' => [],
                'count' => 0,
            ]);
        }

        // Check if KB has any processed documents
        if ($kb->chunk_count === 0) {
            return response()->json([
                'query' => $validated['query'],
                'results' => [],
                'count' => 0,
                'message' => 'No documents have been processed yet',
            ]);
        }

        try {
            $results = $this->searchService->search(
                $kb->id,
                $validated['query'],
                $validated['limit'] ?? 5,
                $validated['threshold'] ?? null
            );

            return response()->json([
                'query' => $validated['query'],
                'results' => $results,
                'count' => $results->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Search failed: ' . $e->getMessage(),
                'query' => $validated['query'],
                'results' => [],
                'count' => 0,
            ], 500);
        }
    }
}
