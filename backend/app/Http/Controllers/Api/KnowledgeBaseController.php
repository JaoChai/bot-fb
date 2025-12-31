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
    /**
     * List all knowledge bases for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $knowledgeBases = KnowledgeBase::where('user_id', $request->user()->id)
            ->with('bot:id,name')
            ->withCount('documents')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $knowledgeBases->map(fn ($kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'description' => $kb->description,
                'bot_id' => $kb->bot_id,
                'bot_name' => $kb->bot?->name,
                'document_count' => $kb->documents_count,
                'chunk_count' => $kb->chunk_count,
            ]),
        ]);
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
     * SemanticSearchService is injected via method injection to avoid loading it for other endpoints.
     */
    public function search(Request $request, Bot $bot, SemanticSearchService $searchService): JsonResponse
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
            // Get API key: User Settings > ENV
            $apiKey = $bot->user?->settings?->openrouter_api_key
                ?? config('services.openrouter.api_key');

            $results = $searchService->search(
                $kb->id,
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
