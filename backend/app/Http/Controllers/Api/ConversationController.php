<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Chat\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private ConversationService $conversationService
    ) {}

    /**
     * List conversations for a bot with filters, pagination, and search.
     */
    public function index(Request $request, Bot $bot): AnonymousResourceCollection|JsonResponse
    {
        try {
            $this->authorize('view', $bot);

            $result = $this->conversationService->listConversations($bot, $request);

            return ConversationResource::collection($result['conversations'])
                ->additional([
                    'meta' => [
                        'status_counts' => $result['status_counts'],
                    ],
                ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('ConversationController@index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * Get a single conversation with messages.
     */
    public function show(Request $request, Bot $bot, Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $messagesLimit = $request->filled('messages_limit') ? (int) $request->messages_limit : null;
        $conversation = $this->conversationService->getConversation($conversation, $messagesLimit);

        return new ConversationResource($conversation);
    }

    /**
     * Update conversation status or metadata.
     */
    public function update(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'status' => 'sometimes|in:active,closed,handover',
            'is_handover' => 'sometimes|boolean',
            'assigned_user_id' => 'sometimes|nullable|exists:users,id',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
            'memory_notes' => 'sometimes|nullable|array',
        ]);

        $conversation = $this->conversationService->updateConversation($conversation, $validated);

        return $this->success(new ConversationResource($conversation), 'Conversation updated successfully');
    }

    /**
     * Close a conversation.
     */
    public function close(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $conversation = $this->conversationService->closeConversation($conversation);

        return $this->success(new ConversationResource($conversation), 'Conversation closed successfully');
    }

    /**
     * Reopen a closed conversation.
     */
    public function reopen(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $conversation = $this->conversationService->reopenConversation($conversation);

        return $this->success(new ConversationResource($conversation), 'Conversation reopened successfully');
    }

    /**
     * Clear bot context - bot will not reference messages before this point.
     */
    public function clearContext(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $conversation = $this->conversationService->clearContext($conversation);

        // Broadcast the update for real-time sync
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return $this->success(new ConversationResource($conversation), 'Bot context cleared successfully');
    }

    /**
     * Clear bot context for ALL active/handover conversations.
     * Bot will start fresh with all open conversations.
     */
    public function clearContextAll(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $updatedCount = $this->conversationService->clearContextAll($bot);

        return $this->success(['updated_count' => $updatedCount], "Reset context for {$updatedCount} conversations");
    }

    /**
     * Get conversation statistics for a bot.
     * Optimized: Single query with CTE instead of 6 separate queries.
     * Cached for 30 seconds to reduce database load.
     */
    public function stats(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $stats = $this->conversationService->getStats($bot);

        return $this->success($stats);
    }

    /**
     * Validate that a conversation belongs to the specified bot.
     */
    private function validateConversationBelongsToBot(Conversation $conversation, Bot $bot): void
    {
        if ($conversation->bot_id !== $bot->id) {
            abort(404, 'Conversation not found');
        }
    }
}
