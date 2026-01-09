<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeBase\StoreKnowledgeBaseRequest;
use App\Http\Requests\KnowledgeBase\UpdateKnowledgeBaseRequest;
use App\Http\Resources\KnowledgeBaseResource;
use App\Models\KnowledgeBase;
use App\Services\SemanticSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeBaseController extends Controller
{
    /**
     * List all knowledge bases for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $knowledgeBases = KnowledgeBase::where('user_id', $request->user()->id)
            ->withCount('documents')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $knowledgeBases->map(fn ($kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'description' => $kb->description,
                'document_count' => $kb->documents_count,
                'chunk_count' => $kb->chunk_count,
                'embedding_model' => $kb->embedding_model,
                'created_at' => $kb->created_at->toISOString(),
                'updated_at' => $kb->updated_at->toISOString(),
            ]),
        ]);
    }

    /**
     * Create a new knowledge base.
     */
    public function store(StoreKnowledgeBaseRequest $request): JsonResponse
    {
        $kb = KnowledgeBase::create([
            'user_id' => $request->user()->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        return response()->json([
            'message' => 'Knowledge base created successfully',
            'data' => new KnowledgeBaseResource($kb),
        ], 201);
    }

    /**
     * Get a specific knowledge base.
     */
    public function show(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorize('view', $knowledgeBase);

        return response()->json([
            'data' => new KnowledgeBaseResource($knowledgeBase->load('documents')),
        ]);
    }

    /**
     * Update knowledge base settings.
     */
    public function update(UpdateKnowledgeBaseRequest $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorize('update', $knowledgeBase);

        $knowledgeBase->update($request->validated());

        return response()->json([
            'message' => 'Knowledge base updated successfully',
            'data' => new KnowledgeBaseResource($knowledgeBase->fresh()),
        ]);
    }

    /**
     * Delete a knowledge base.
     */
    public function destroy(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorize('delete', $knowledgeBase);

        // This will also delete related documents due to cascade
        $knowledgeBase->delete();

        return response()->json([
            'message' => 'Knowledge base deleted successfully',
        ]);
    }

    /**
     * Search knowledge base using semantic similarity.
     */
    public function search(Request $request, KnowledgeBase $knowledgeBase, SemanticSearchService $searchService): JsonResponse
    {
        $this->authorize('view', $knowledgeBase);

        $validated = $request->validate([
            'query' => 'required|string|min:1|max:1000',
            'limit' => 'integer|min:1|max:20',
            'threshold' => 'numeric|min:0|max:1',
        ]);

        // Check if KB has any processed documents
        if ($knowledgeBase->chunk_count === 0) {
            return response()->json([
                'query' => $validated['query'],
                'results' => [],
                'count' => 0,
                'message' => 'No documents have been processed yet',
            ]);
        }

        try {
            // Get API key: User Settings > ENV
            $apiKey = $knowledgeBase->user?->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

            $results = $searchService->search(
                $knowledgeBase->id,
                $validated['query'],
                $validated['limit'] ?? 5,
                $validated['threshold'] ?? null,
                $apiKey
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
