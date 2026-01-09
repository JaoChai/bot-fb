<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    /**
     * Display the chat page.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        // Get user's bots for the selector
        $bots = Bot::forUser($user)
            ->select(['id', 'name', 'channel_type', 'status'])
            ->orderBy('name')
            ->get();

        // Get selected bot ID from query param
        $selectedBotId = $request->query('botId');
        $selectedBot = null;
        $conversations = null;

        if ($selectedBotId) {
            $selectedBot = $bots->firstWhere('id', (int) $selectedBotId);

            if ($selectedBot) {
                // Get conversations for this bot with pagination
                $conversations = $this->getConversations($request, $selectedBot);
            }
        }

        return Inertia::render('Chat/Index', [
            'bots' => $bots,
            'selectedBotId' => $selectedBotId ? (int) $selectedBotId : null,
            'conversations' => $conversations,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Get messages for a conversation (called via Inertia visit).
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        $this->authorize('view', $conversation);

        $user = Auth::user();

        // Get user's bots
        $bots = Bot::forUser($user)
            ->select(['id', 'name', 'channel_type', 'status'])
            ->orderBy('name')
            ->get();

        $selectedBot = $conversation->bot;

        // Get conversations for sidebar
        $conversationsRequest = new Request($request->all());
        $conversationsRequest->merge(['botId' => $selectedBot->id]);
        $conversations = $this->getConversations($conversationsRequest, $selectedBot);

        // Get messages with pagination
        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Mark conversation as read
        $conversation->update(['unread_count' => 0]);

        return Inertia::render('Chat/Index', [
            'bots' => $bots,
            'selectedBotId' => $selectedBot->id,
            'conversations' => $conversations,
            'selectedConversation' => $conversation->load(['customer', 'bot']),
            'messages' => $messages,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Get conversations for a bot with filters and pagination.
     */
    protected function getConversations(Request $request, Bot $bot): array
    {
        $query = Conversation::where('bot_id', $bot->id)
            ->with(['customer', 'latestMessage'])
            ->withCount('messages');

        // Apply search filter
        if ($search = $request->query('search')) {
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('display_name', 'like', "%{$search}%")
                    ->orWhere('platform_id', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Order by last message
        $query->orderByDesc('last_message_at');

        $paginated = $query->paginate(30);

        return [
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'links' => [
                'next' => $paginated->nextPageUrl(),
                'prev' => $paginated->previousPageUrl(),
            ],
        ];
    }

    /**
     * Load more messages (AJAX for infinite scroll).
     */
    public function loadMoreMessages(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(50);

        return response()->json($messages);
    }

    /**
     * Send a message (via AJAX).
     */
    public function sendMessage(Request $request, Conversation $conversation)
    {
        $this->authorize('update', $conversation);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'message_type' => ['sometimes', 'string', 'in:text,image,sticker,file'],
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'agent',
            'sender_id' => Auth::id(),
            'content' => $validated['content'],
            'message_type' => $validated['message_type'] ?? 'text',
        ]);

        // Update conversation last_message_at
        $conversation->update(['last_message_at' => now()]);

        // TODO: Send message to channel (LINE/FB/Telegram) via service

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Toggle HITL mode for a conversation.
     */
    public function toggleHitl(Request $request, Conversation $conversation)
    {
        $this->authorize('update', $conversation);

        $validated = $request->validate([
            'hitl_mode' => ['required', 'boolean'],
        ]);

        $conversation->update([
            'hitl_mode' => $validated['hitl_mode'],
        ]);

        return response()->json([
            'success' => true,
            'hitl_mode' => $conversation->hitl_mode,
        ]);
    }

    /**
     * Get customer details.
     */
    public function customerDetails(Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        return response()->json([
            'customer' => $conversation->customer,
            'stats' => [
                'total_conversations' => $conversation->customer->conversations()->count(),
                'total_messages' => $conversation->customer->messages()->count(),
                'first_contact' => $conversation->customer->created_at,
            ],
        ]);
    }
}
