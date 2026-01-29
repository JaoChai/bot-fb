<?php

namespace App\Http\Controllers\Api;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Chat\NoteService;
use App\Services\ConversationCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationNoteController extends BaseConversationController
{
    public function __construct(
        ConversationCacheService $cacheService,
        private NoteService $noteService
    ) {
        parent::__construct($cacheService);
    }

    /**
     * Get all notes for a conversation.
     */
    public function index(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $notes = $this->noteService->getNotes($conversation);

        return response()->json([
            'data' => $notes,
        ]);
    }

    /**
     * Add a note to a conversation's memory.
     */
    public function store(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'type' => 'sometimes|string|in:note,memory,reminder',
        ]);

        $newNote = $this->noteService->addNote($conversation, $validated, $request->user()->id);

        return response()->json([
            'message' => 'Note added successfully',
            'data' => $newNote,
        ], 201);
    }

    /**
     * Update a note in a conversation's memory.
     */
    public function update(Request $request, Bot $bot, Conversation $conversation, string $noteId): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'type' => 'sometimes|string|in:note,memory,reminder',
        ]);

        $updatedNote = $this->noteService->updateNote($conversation, $noteId, $validated);

        return response()->json([
            'message' => 'Note updated successfully',
            'data' => $updatedNote,
        ]);
    }

    /**
     * Delete a note from a conversation's memory.
     */
    public function destroy(Request $request, Bot $bot, Conversation $conversation, string $noteId): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $this->noteService->deleteNote($conversation, $noteId);

        return response()->json([
            'message' => 'Note deleted successfully',
        ]);
    }

}
