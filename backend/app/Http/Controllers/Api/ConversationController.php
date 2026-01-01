<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\LINEService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{
    /**
     * List conversations for a bot with filters, pagination, and search.
     */
    public function index(Request $request, Bot $bot): AnonymousResourceCollection|JsonResponse
    {
        try {
            $this->authorize('view', $bot);

            $query = $bot->conversations()
                ->with(['customerProfile', 'assignedUser', 'lastMessage']);

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

        // Filter by Telegram chat type (private, group, supergroup, channel)
        if ($request->filled('telegram_chat_type')) {
            $chatTypes = is_array($request->telegram_chat_type)
                ? $request->telegram_chat_type
                : explode(',', $request->telegram_chat_type);
            $query->whereIn('telegram_chat_type', $chatTypes);
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
        // Uses full-text search on customer_profiles (GIN index) for performance
        if ($request->filled('search')) {
            $search = $request->search;
            $query->leftJoin('customer_profiles as cp_search', 'conversations.customer_profile_id', '=', 'cp_search.id')
                ->where(function ($q) use ($search) {
                    // External customer ID - still uses ILIKE (indexed)
                    $q->where('conversations.external_customer_id', 'ilike', "%{$search}%")
                        // Full-text search on customer_profiles using GIN index
                        ->orWhereRaw(
                            "to_tsvector('simple', coalesce(cp_search.display_name, '') || ' ' || coalesce(cp_search.email, '') || ' ' || coalesce(cp_search.phone, '')) @@ plainto_tsquery('simple', ?)",
                            [$search]
                        );
                })
                ->select('conversations.*'); // Ensure only conversation columns are returned
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

        // Count conversations needing response (last message is from customer)
        // Only for auto_handover bots, otherwise return 0
        $needsResponseCount = 0;
        if ($bot->auto_handover) {
            $needsResponseCount = $bot->conversations()
                ->where('status', '!=', 'closed')
                ->whereHas('lastMessage', fn ($q) => $q->where('is_from_customer', true))
                ->count();
        }

        $conversations = $query->paginate($request->input('per_page', 20));

        return ConversationResource::collection($conversations)
            ->additional([
                'meta' => [
                    'status_counts' => [
                        'active' => $statusCounts['active'] ?? 0,
                        'closed' => $statusCounts['closed'] ?? 0,
                        'handover' => $statusCounts['handover'] ?? 0,
                        'total' => array_sum($statusCounts),
                        'needs_response' => $needsResponseCount,
                    ],
                ],
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // Let Laravel handle authorization exceptions naturally (403)
            throw $e;
        } catch (\Throwable $e) {
            Log::error('ConversationController@index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
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
     * Enforces max 100 messages per page to prevent memory issues.
     */
    public function messages(Request $request, Bot $bot, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        // Enforce maximum limit of 100 messages per page
        $perPage = min((int) $request->input('per_page', 50), 100);

        $messages = $conversation->messages()
            ->orderBy('created_at', $request->input('order', 'desc'))
            ->paginate($perPage);

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
        $conversation->load(['customerProfile', 'assignedUser']);

        return response()->json([
            'message' => 'Conversation updated successfully',
            'data' => new ConversationResource($conversation),
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
        $conversation->load(['customerProfile']);

        // Invalidate stats cache
        Cache::forget("bot:{$bot->id}:conversation:stats");

        return response()->json([
            'message' => 'Conversation closed successfully',
            'data' => new ConversationResource($conversation),
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
        $conversation->load(['customerProfile']);

        // Invalidate stats cache
        Cache::forget("bot:{$bot->id}:conversation:stats");

        return response()->json([
            'message' => 'Conversation reopened successfully',
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * Toggle handover mode (human-in-the-loop) with auto-enable timer.
     */
    public function toggleHandover(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $isHandover = ! $conversation->is_handover;

        // Auto-enable timeout in minutes (default: 30)
        $autoEnableMinutes = $request->input('auto_enable_minutes', 30);

        $updateData = [
            'is_handover' => $isHandover,
            'status' => $isHandover ? 'handover' : 'active',
        ];

        // When enabling handover (disabling bot)
        if ($isHandover) {
            // Assign to current user if not assigned
            if (! $conversation->assigned_user_id) {
                $updateData['assigned_user_id'] = $request->user()->id;
            }
            // Set auto-enable timer (bot will auto-enable after X minutes)
            if ($autoEnableMinutes > 0) {
                $updateData['bot_auto_enable_at'] = now()->addMinutes($autoEnableMinutes);
            } else {
                $updateData['bot_auto_enable_at'] = null;
            }
        } else {
            // When disabling handover (enabling bot)
            $updateData['bot_auto_enable_at'] = null; // Clear timer
            // Optionally unassign
            if ($request->boolean('unassign', false)) {
                $updateData['assigned_user_id'] = null;
            }
        }

        $conversation->update($updateData);

        // Log activity
        $customerName = $conversation->customerProfile?->display_name ?? $conversation->external_customer_id;
        ActivityLog::log(
            userId: $request->user()->id,
            type: $isHandover ? ActivityLog::TYPE_HANDOVER_STARTED : ActivityLog::TYPE_HANDOVER_RESOLVED,
            title: $isHandover ? 'เปิด Handover Mode' : 'ปิด Handover Mode',
            description: "ลูกค้า: {$customerName}",
            botId: $bot->id,
            metadata: ['conversation_id' => $conversation->id]
        );

        // Load relationships for broadcast and response
        $conversation->load(['customerProfile', 'assignedUser']);

        // Invalidate stats cache (status changed to handover or active)
        Cache::forget("bot:{$bot->id}:conversation:stats");

        // Broadcast the update for real-time sync
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => $isHandover ? 'Handover mode enabled' : 'Bot mode enabled',
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * Mark conversation as read (reset unread count).
     */
    public function markAsRead(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);

            // Broadcast the update for real-time sync
            broadcast(new ConversationUpdated($conversation))->toOthers();
        }

        $conversation->load(['customerProfile']);

        return response()->json([
            'message' => 'Conversation marked as read',
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * Clear bot context - bot will not reference messages before this point.
     */
    public function clearContext(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $conversation->update(['context_cleared_at' => now()]);
        $conversation->load(['customerProfile']);

        // Broadcast the update for real-time sync
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Bot context cleared successfully',
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * Get conversation statistics for a bot.
     * Optimized: Single query with CTE instead of 6 separate queries.
     * Cached for 30 seconds to reduce database load.
     */
    public function stats(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $cacheKey = "bot:{$bot->id}:conversation:stats";

        $stats = Cache::remember($cacheKey, 30, fn () => DB::selectOne("
            WITH conv_base AS (
                SELECT
                    id,
                    status,
                    channel_type,
                    message_count
                FROM conversations
                WHERE bot_id = ? AND deleted_at IS NULL
            ),
            status_counts AS (
                SELECT
                    COUNT(*) as total,
                    COUNT(*) FILTER (WHERE status = 'active') as active,
                    COUNT(*) FILTER (WHERE status = 'closed') as closed,
                    COUNT(*) FILTER (WHERE status = 'handover') as handover,
                    COALESCE(AVG(message_count), 0) as avg_messages
                FROM conv_base
            ),
            channel_counts AS (
                SELECT jsonb_object_agg(channel_type, cnt) as by_channel
                FROM (
                    SELECT channel_type, COUNT(*) as cnt
                    FROM conv_base
                    GROUP BY channel_type
                ) sub
            ),
            messages_today AS (
                SELECT COUNT(*) as count
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.id
                WHERE c.bot_id = ? AND c.deleted_at IS NULL
                    AND m.created_at >= CURRENT_DATE
                    AND m.created_at < CURRENT_DATE + INTERVAL '1 day'
            )
            SELECT
                sc.total,
                sc.active,
                sc.closed,
                sc.handover,
                sc.avg_messages,
                mt.count as messages_today,
                COALESCE(cc.by_channel, '{}'::jsonb) as by_channel
            FROM status_counts sc
            CROSS JOIN messages_today mt
            CROSS JOIN channel_counts cc
        ", [$bot->id, $bot->id]));

        return response()->json([
            'data' => [
                'total' => (int) ($stats->total ?? 0),
                'active' => (int) ($stats->active ?? 0),
                'closed' => (int) ($stats->closed ?? 0),
                'handover' => (int) ($stats->handover ?? 0),
                'messages_today' => (int) ($stats->messages_today ?? 0),
                'avg_messages_per_conversation' => round((float) ($stats->avg_messages ?? 0), 1),
                'by_channel' => json_decode($stats->by_channel ?? '{}', true),
            ],
        ]);
    }

    /**
     * Add a note to a conversation's memory.
     */
    public function addNote(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'type' => 'sometimes|string|in:note,memory,reminder',
        ]);

        $notes = $conversation->memory_notes ?? [];
        $newNote = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content' => $validated['content'],
            'type' => $validated['type'] ?? 'note',
            'created_by' => $request->user()->id,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        $notes[] = $newNote;
        $conversation->update(['memory_notes' => $notes]);

        return response()->json([
            'message' => 'Note added successfully',
            'data' => $newNote,
        ], 201);
    }

    /**
     * Update a note in a conversation's memory.
     */
    public function updateNote(Request $request, Bot $bot, Conversation $conversation, string $noteId): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'type' => 'sometimes|string|in:note,memory,reminder',
        ]);

        $notes = $conversation->memory_notes ?? [];
        $noteIndex = collect($notes)->search(fn ($note) => $note['id'] === $noteId);

        if ($noteIndex === false) {
            abort(404, 'Note not found');
        }

        $notes[$noteIndex]['content'] = $validated['content'];
        if (isset($validated['type'])) {
            $notes[$noteIndex]['type'] = $validated['type'];
        }
        $notes[$noteIndex]['updated_at'] = now()->toISOString();

        $conversation->update(['memory_notes' => array_values($notes)]);

        return response()->json([
            'message' => 'Note updated successfully',
            'data' => $notes[$noteIndex],
        ]);
    }

    /**
     * Delete a note from a conversation's memory.
     */
    public function deleteNote(Request $request, Bot $bot, Conversation $conversation, string $noteId): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $notes = $conversation->memory_notes ?? [];
        $filteredNotes = collect($notes)->filter(fn ($note) => $note['id'] !== $noteId)->values()->all();

        if (count($filteredNotes) === count($notes)) {
            abort(404, 'Note not found');
        }

        $conversation->update(['memory_notes' => $filteredNotes]);

        return response()->json([
            'message' => 'Note deleted successfully',
        ]);
    }

    /**
     * Get all notes for a conversation.
     */
    public function getNotes(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $notes = $conversation->memory_notes ?? [];

        // Sort by created_at descending (newest first)
        $sortedNotes = collect($notes)->sortByDesc('created_at')->values()->all();

        return response()->json([
            'data' => $sortedNotes,
        ]);
    }

    /**
     * Add tags to a conversation.
     */
    public function addTags(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'tags' => 'required|array|min:1',
            'tags.*' => 'string|max:50',
        ]);

        $currentTags = $conversation->tags ?? [];
        $newTags = array_unique(array_merge($currentTags, $validated['tags']));

        $conversation->update(['tags' => array_values($newTags)]);

        // Invalidate tags cache
        Cache::forget("bot:{$bot->id}:conversation:tags");

        return response()->json([
            'message' => 'Tags added successfully',
            'data' => ['tags' => $newTags],
        ]);
    }

    /**
     * Remove a tag from a conversation.
     */
    public function removeTag(Request $request, Bot $bot, Conversation $conversation, string $tag): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $currentTags = $conversation->tags ?? [];
        $filteredTags = array_values(array_filter($currentTags, fn ($t) => $t !== $tag));

        if (count($filteredTags) === count($currentTags)) {
            abort(404, 'Tag not found');
        }

        $conversation->update(['tags' => $filteredTags]);

        // Invalidate tags cache
        Cache::forget("bot:{$bot->id}:conversation:tags");

        return response()->json([
            'message' => 'Tag removed successfully',
            'data' => ['tags' => $filteredTags],
        ]);
    }

    /**
     * Bulk add tags to multiple conversations.
     */
    public function bulkAddTags(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'conversation_ids' => 'required|array|min:1|max:100',
            'conversation_ids.*' => 'integer|exists:conversations,id',
            'tags' => 'required|array|min:1',
            'tags.*' => 'string|max:50',
        ]);

        // Verify all conversations belong to this bot
        $conversationIds = $validated['conversation_ids'];
        $conversations = $bot->conversations()->whereIn('id', $conversationIds)->get();

        if ($conversations->count() !== count($conversationIds)) {
            abort(400, 'Some conversations do not belong to this bot');
        }

        $updated = 0;
        foreach ($conversations as $conversation) {
            $currentTags = $conversation->tags ?? [];
            $newTags = array_unique(array_merge($currentTags, $validated['tags']));
            $conversation->update(['tags' => array_values($newTags)]);
            $updated++;
        }

        // Invalidate tags cache
        Cache::forget("bot:{$bot->id}:conversation:tags");

        return response()->json([
            'message' => "Tags added to {$updated} conversations",
            'data' => ['updated_count' => $updated],
        ]);
    }

    /**
     * Get all unique tags used in bot conversations.
     * Optimized: SQL aggregation instead of fetching all rows.
     * Cached for 60 seconds to reduce database load.
     */
    public function getAllTags(Request $request, Bot $bot): JsonResponse
    {
        try {
            $this->authorize('view', $bot);

            $cacheKey = "bot:{$bot->id}:conversation:tags";

            $tags = Cache::remember($cacheKey, 60, fn () => DB::select('
                SELECT DISTINCT jsonb_array_elements_text(tags) as tag
                FROM conversations
                WHERE bot_id = ?
                    AND deleted_at IS NULL
                    AND tags IS NOT NULL
                    AND jsonb_array_length(tags) > 0
                ORDER BY tag
            ', [$bot->id]));

            return response()->json([
                'data' => array_column($tags, 'tag'),
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('ConversationController@getAllTags error', [
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
     * Send a message from agent to customer (HITL).
     */
    public function sendAgentMessage(
        Request $request,
        Bot $bot,
        Conversation $conversation,
        LINEService $lineService,
        TelegramService $telegramService
    ): JsonResponse {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        // Must be in handover mode
        if (! $conversation->is_handover) {
            return response()->json([
                'message' => 'Conversation must be in handover mode to send agent messages',
            ], 400);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000', function ($attribute, $value, $fail) {
                // LINE API has 5000 byte limit, not character limit
                if (strlen($value) > 5000) {
                    $fail('The message is too long (max 5000 bytes for LINE).');
                }
            }],
            'type' => 'sometimes|in:text,image,file,photo,video,document,voice',
            'media_url' => 'sometimes|url|max:2048',
        ]);

        $sendError = null;

        // Use transaction for data consistency
        $message = DB::transaction(function () use ($validated, $conversation) {
            // Create the message in database
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender' => 'agent',
                'content' => $validated['content'],
                'type' => $validated['type'] ?? 'text',
                'media_url' => $validated['media_url'] ?? null,
            ]);

            // Update conversation stats atomically
            $conversation->update(['last_message_at' => now()]);
            $conversation->increment('message_count');

            return $message;
        });

        // Send to customer via channel (outside transaction - external API call)
        try {
            if ($conversation->channel_type === 'line') {
                $type = $validated['type'] ?? 'text';
                $userId = $conversation->external_customer_id;
                $mediaUrl = $validated['media_url'] ?? null;

                switch ($type) {
                    case 'photo':
                    case 'image':
                        if ($mediaUrl) {
                            $lineService->push($bot, $userId, [
                                $lineService->imageMessage($mediaUrl),
                            ]);
                        } else {
                            $lineService->push($bot, $userId, [
                                $lineService->textMessage($validated['content']),
                            ]);
                        }
                        break;
                    case 'video':
                        if ($mediaUrl) {
                            $lineService->push($bot, $userId, [
                                $lineService->videoMessage($mediaUrl),
                            ]);
                        } else {
                            $lineService->push($bot, $userId, [
                                $lineService->textMessage($validated['content']),
                            ]);
                        }
                        break;
                    case 'audio':
                    case 'voice':
                        if ($mediaUrl) {
                            $lineService->push($bot, $userId, [
                                $lineService->audioMessage($mediaUrl),
                            ]);
                        } else {
                            $lineService->push($bot, $userId, [
                                $lineService->textMessage($validated['content']),
                            ]);
                        }
                        break;
                    default:
                        $lineService->push($bot, $userId, [
                            $lineService->textMessage($validated['content']),
                        ]);
                }
            } elseif ($conversation->channel_type === 'telegram') {
                $type = $validated['type'] ?? 'text';
                $chatId = $conversation->external_customer_id;
                $mediaUrl = $validated['media_url'] ?? null;

                switch ($type) {
                    case 'photo':
                    case 'image':
                        if ($mediaUrl) {
                            $telegramService->sendPhoto($bot, $chatId, $mediaUrl, $validated['content'] ?: null);
                        } else {
                            $telegramService->sendMessage($bot, $chatId, $validated['content']);
                        }
                        break;
                    case 'video':
                        if ($mediaUrl) {
                            $telegramService->sendVideo($bot, $chatId, $mediaUrl, $validated['content'] ?: null);
                        } else {
                            $telegramService->sendMessage($bot, $chatId, $validated['content']);
                        }
                        break;
                    case 'document':
                    case 'file':
                        if ($mediaUrl) {
                            $telegramService->sendDocument($bot, $chatId, $mediaUrl, $validated['content'] ?: null);
                        } else {
                            $telegramService->sendMessage($bot, $chatId, $validated['content']);
                        }
                        break;
                    case 'voice':
                        if ($mediaUrl) {
                            $telegramService->sendVoice($bot, $chatId, $mediaUrl);
                        } else {
                            $telegramService->sendMessage($bot, $chatId, $validated['content']);
                        }
                        break;
                    default:
                        $telegramService->sendMessage($bot, $chatId, $validated['content']);
                }
            }
            // Add other channel implementations here (Facebook, etc.)
        } catch (\Exception $e) {
            Log::error('Failed to send agent message to customer', [
                'conversation_id' => $conversation->id,
                'bot_id' => $bot->id,
                'channel_type' => $conversation->channel_type,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);
            $sendError = 'Failed to deliver message to customer';
        }

        // Reload conversation with updated stats for broadcast
        $conversation->refresh();

        // Broadcast the message for real-time updates
        broadcast(new MessageSent($message))->toOthers();
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Message sent successfully',
            'data' => new MessageResource($message),
            'delivery_error' => $sendError,
        ], 201);
    }

    /**
     * Assign conversation to a specific admin (owner only).
     */
    public function assign(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        // Verify user is an admin of this bot or the owner
        $targetUser = \App\Models\User::find($validated['user_id']);
        if (!$targetUser->canAccessBot($bot)) {
            return response()->json([
                'message' => 'User is not an admin of this bot',
            ], 422);
        }

        $conversation->update([
            'assigned_user_id' => $validated['user_id'],
        ]);
        $conversation->load(['customerProfile', 'assignedUser']);

        // Broadcast the update
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Conversation assigned successfully',
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * Admin claims a conversation for themselves.
     */
    public function claim(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $user = $request->user();

        // Must be unassigned or already assigned to this user
        if ($conversation->assigned_user_id && $conversation->assigned_user_id !== $user->id) {
            return response()->json([
                'message' => 'Conversation is already assigned to another user',
            ], 422);
        }

        $conversation->update([
            'assigned_user_id' => $user->id,
        ]);
        $conversation->load(['customerProfile', 'assignedUser']);

        // Broadcast the update
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Conversation claimed successfully',
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * Release conversation assignment.
     */
    public function unassign(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $user = $request->user();

        // Only owner or the assigned user can unassign
        $isOwner = $user->isOwner() && $user->id === $bot->user_id;
        $isAssignedUser = $conversation->assigned_user_id === $user->id;

        if (!$isOwner && !$isAssignedUser) {
            return response()->json([
                'message' => 'You can only unassign conversations assigned to you',
            ], 403);
        }

        $conversation->update([
            'assigned_user_id' => null,
        ]);
        $conversation->load(['customerProfile', 'assignedUser']);

        // Broadcast the update
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Conversation unassigned successfully',
            'data' => new ConversationResource($conversation),
        ]);
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

    /**
     * Upload media file for agent messages.
     * Stores the file and returns the public URL.
     */
    public function uploadMedia(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $request->validate([
            'file' => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,webp,mp4,mov,avi,webm,mp3,m4a,wav,ogg',
        ]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType();

        // Determine type from mime
        $type = 'file';
        if (str_starts_with($mimeType, 'image/')) {
            $type = 'image';
        } elseif (str_starts_with($mimeType, 'video/')) {
            $type = 'video';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            $type = 'audio';
        }

        // Generate storage path
        $extension = $file->getClientOriginalExtension();
        $storagePath = 'chat/' . $bot->id . '/' . date('Y/m/d') . '/' . uniqid() . '.' . $extension;

        // Store file
        $disk = config('filesystems.default');
        $file->storeAs(dirname($storagePath), basename($storagePath), $disk);

        // Generate URL
        $url = $this->generateStorageUrl($disk, $storagePath);

        return response()->json([
            'url' => $url,
            'type' => $type,
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'filename' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Generate storage URL - use R2_URL directly if R2 disk.
     */
    private function generateStorageUrl(string $disk, string $path): string
    {
        if ($disk === 'r2') {
            $r2Url = env('R2_URL') ?: config('filesystems.disks.r2.url');
            if ($r2Url) {
                return rtrim($r2Url, '/') . '/' . $path;
            }
        }

        return \Illuminate\Support\Facades\Storage::disk($disk)->url($path);
    }
}
