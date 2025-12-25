<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    /**
     * List conversations for a bot with filters, pagination, and search.
     */
    public function index(Request $request, Bot $bot): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);

        $query = $bot->conversations()
            ->with(['customerProfile', 'assignedUser']);

        // Filter by status
        if ($request->filled('status')) {
            $statuses = is_array($request->status)
                ? $request->status
                : explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        // Filter by channel type
        if ($request->filled('channel_type')) {
            $query->where('channel_type', $request->channel_type);
        }

        // Filter by handover status
        if ($request->has('is_handover')) {
            $query->where('is_handover', filter_var($request->is_handover, FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by assigned user
        if ($request->filled('assigned_user_id')) {
            $query->where('assigned_user_id', $request->assigned_user_id);
        }

        // Filter by tags
        if ($request->filled('tags')) {
            $tags = is_array($request->tags) ? $request->tags : explode(',', $request->tags);
            foreach ($tags as $tag) {
                $query->whereJsonContains('tags', trim($tag));
            }
        }

        // Search by external customer ID or customer profile name
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('external_customer_id', 'ilike', "%{$search}%")
                  ->orWhereHas('customerProfile', function ($q) use ($search) {
                      $q->where('display_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'ilike', "%{$search}%");
                  });
            });
        }

        // Date range filters
        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->to_date);
        }

        // Sorting
        $sortField = $request->input('sort_by', 'last_message_at');
        $sortDirection = $request->input('sort_direction', 'desc');

        $allowedSortFields = ['last_message_at', 'created_at', 'message_count', 'status'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('last_message_at');
        }

        // Get counts by status for the sidebar
        $statusCounts = $bot->conversations()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $conversations = $query->paginate($request->input('per_page', 20));

        return ConversationResource::collection($conversations)
            ->additional([
                'meta' => [
                    'status_counts' => [
                        'active' => $statusCounts['active'] ?? 0,
                        'closed' => $statusCounts['closed'] ?? 0,
                        'handover' => $statusCounts['handover'] ?? 0,
                        'total' => array_sum($statusCounts),
                    ],
                ],
            ]);
    }

    /**
     * Get a single conversation with messages.
     */
    public function show(Request $request, Bot $bot, Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $conversation->load([
            'customerProfile',
            'assignedUser',
            'currentFlow',
            'messages' => function ($query) use ($request) {
                $query->orderBy('created_at', 'asc');

                // Limit messages if requested
                if ($request->filled('messages_limit')) {
                    $query->limit((int) $request->messages_limit);
                }
            },
        ]);

        return new ConversationResource($conversation);
    }

    /**
     * Get messages for a conversation with pagination.
     */
    public function messages(Request $request, Bot $bot, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $messages = $conversation->messages()
            ->orderBy('created_at', $request->input('order', 'desc'))
            ->paginate($request->input('per_page', 50));

        return MessageResource::collection($messages);
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

        // Cast boolean field for PostgreSQL
        if (isset($validated['is_handover'])) {
            $validated['is_handover'] = (bool) $validated['is_handover'];
        }

        $conversation->update($validated);

        return response()->json([
            'message' => 'Conversation updated successfully',
            'data' => new ConversationResource($conversation->fresh(['customerProfile', 'assignedUser'])),
        ]);
    }

    /**
     * Close a conversation.
     */
    public function close(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $conversation->update([
            'status' => 'closed',
            'is_handover' => false,
            'assigned_user_id' => null,
        ]);

        return response()->json([
            'message' => 'Conversation closed successfully',
            'data' => new ConversationResource($conversation->fresh(['customerProfile'])),
        ]);
    }

    /**
     * Reopen a closed conversation.
     */
    public function reopen(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $conversation->update([
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Conversation reopened successfully',
            'data' => new ConversationResource($conversation->fresh(['customerProfile'])),
        ]);
    }

    /**
     * Toggle handover mode (human-in-the-loop).
     */
    public function toggleHandover(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $isHandover = !$conversation->is_handover;

        $updateData = [
            'is_handover' => $isHandover,
            'status' => $isHandover ? 'handover' : 'active',
        ];

        // Assign to current user when enabling handover
        if ($isHandover && !$conversation->assigned_user_id) {
            $updateData['assigned_user_id'] = $request->user()->id;
        }

        // Optionally unassign when disabling handover
        if (!$isHandover && $request->boolean('unassign', false)) {
            $updateData['assigned_user_id'] = null;
        }

        $conversation->update($updateData);

        return response()->json([
            'message' => $isHandover ? 'Handover mode enabled' : 'Handover mode disabled',
            'data' => new ConversationResource($conversation->fresh(['customerProfile', 'assignedUser'])),
        ]);
    }

    /**
     * Get conversation statistics for a bot.
     */
    public function stats(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $stats = [
            'total' => $bot->conversations()->count(),
            'active' => $bot->conversations()->where('status', 'active')->count(),
            'closed' => $bot->conversations()->where('status', 'closed')->count(),
            'handover' => $bot->conversations()->where('status', 'handover')->count(),
            'messages_today' => $bot->conversations()
                ->join('messages', 'conversations.id', '=', 'messages.conversation_id')
                ->whereDate('messages.created_at', today())
                ->count(),
            'avg_messages_per_conversation' => round(
                $bot->conversations()->avg('message_count') ?? 0,
                1
            ),
        ];

        // Channel breakdown
        $stats['by_channel'] = $bot->conversations()
            ->selectRaw('channel_type, COUNT(*) as count')
            ->groupBy('channel_type')
            ->pluck('count', 'channel_type')
            ->toArray();

        return response()->json(['data' => $stats]);
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
