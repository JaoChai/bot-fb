<?php

namespace App\Http\Controllers\Api;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Chat\TagService;
use App\Services\ConversationCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConversationTagController extends BaseConversationController
{
    public function __construct(
        ConversationCacheService $cacheService,
        private TagService $tagService
    ) {
        parent::__construct($cacheService);
    }

    /**
     * Get all unique tags used in bot conversations.
     * Optimized: SQL aggregation instead of fetching all rows.
     * Cached for 60 seconds to reduce database load.
     */
    public function index(Request $request, Bot $bot): JsonResponse
    {
        try {
            $this->authorize('view', $bot);

            $tags = $this->tagService->getAllTags($bot);

            return response()->json([
                'data' => $tags,
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('ConversationTagController@index error', [
                'message' => $e->getMessage(),
                'bot_id' => $bot->id,
            ]);
            return response()->json([
                'error' => $e->getMessage(),
                'type' => get_class($e),
            ], 500);
        }
    }

    /**
     * Add tags to a conversation.
     */
    public function store(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'tags' => 'required|array|min:1',
            'tags.*' => 'string|max:50',
        ]);

        $newTags = $this->tagService->addTags($conversation, $validated['tags']);

        return response()->json([
            'message' => 'Tags added successfully',
            'data' => ['tags' => $newTags],
        ]);
    }

    /**
     * Remove a tag from a conversation.
     */
    public function destroy(Request $request, Bot $bot, Conversation $conversation, string $tag): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $filteredTags = $this->tagService->removeTag($conversation, $tag);

        return response()->json([
            'message' => 'Tag removed successfully',
            'data' => ['tags' => $filteredTags],
        ]);
    }

    /**
     * Bulk add tags to multiple conversations.
     */
    public function bulkStore(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'conversation_ids' => 'required|array|min:1|max:100',
            'conversation_ids.*' => 'integer|exists:conversations,id',
            'tags' => 'required|array|min:1',
            'tags.*' => 'string|max:50',
        ]);

        try {
            $updated = $this->tagService->bulkAddTags(
                $bot,
                $validated['conversation_ids'],
                $validated['tags']
            );

            return response()->json([
                'message' => "Tags added to {$updated} conversations",
                'data' => ['updated_count' => $updated],
            ]);
        } catch (\InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        }
    }

}
