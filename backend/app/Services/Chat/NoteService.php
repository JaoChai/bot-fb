<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use Illuminate\Support\Str;

class NoteService
{
    /**
     * Get all notes for a conversation, sorted by created_at descending.
     */
    public function getNotes(Conversation $conversation): array
    {
        $notes = $conversation->memory_notes ?? [];

        // Guard: memory_notes must be a sequential array of note objects.
        // Some conversations have legacy object format (e.g. {"vip": true, ...})
        if (! is_array($notes) || (! empty($notes) && ! array_is_list($notes))) {
            return [];
        }

        return collect($notes)
            ->filter(fn ($note) => is_array($note) && isset($note['id'], $note['content'], $note['created_at']))
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /**
     * Add a note to a conversation's memory.
     */
    public function addNote(Conversation $conversation, array $data, int $userId): array
    {
        $notes = $conversation->memory_notes ?? [];

        $newNote = [
            'id' => (string) Str::uuid(),
            'content' => $data['content'],
            'type' => $data['type'] ?? 'note',
            'created_by' => $userId,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        $notes[] = $newNote;
        $conversation->update(['memory_notes' => $notes]);

        return $newNote;
    }

    /**
     * Update a note in a conversation's memory.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function updateNote(Conversation $conversation, string $noteId, array $data): array
    {
        $notes = $conversation->memory_notes ?? [];
        $noteIndex = collect($notes)->search(fn ($note) => $note['id'] === $noteId);

        if ($noteIndex === false) {
            abort(404, 'Note not found');
        }

        $notes[$noteIndex]['content'] = $data['content'];
        if (isset($data['type'])) {
            $notes[$noteIndex]['type'] = $data['type'];
        }
        $notes[$noteIndex]['updated_at'] = now()->toISOString();

        $conversation->update(['memory_notes' => array_values($notes)]);

        return $notes[$noteIndex];
    }

    /**
     * Delete a note from a conversation's memory.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function deleteNote(Conversation $conversation, string $noteId): void
    {
        $notes = $conversation->memory_notes ?? [];
        $filteredNotes = collect($notes)->filter(fn ($note) => $note['id'] !== $noteId)->values()->all();

        if (count($filteredNotes) === count($notes)) {
            abort(404, 'Note not found');
        }

        $conversation->update(['memory_notes' => $filteredNotes]);
    }
}
