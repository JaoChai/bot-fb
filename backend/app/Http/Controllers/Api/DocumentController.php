<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeBase\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocument;
use App\Models\Bot;
use App\Models\Document;
use App\Models\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * List all documents for a bot's knowledge base.
     */
    public function index(Request $request, Bot $bot): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);

        $kb = $bot->knowledgeBase;

        if (!$kb) {
            return DocumentResource::collection(collect([]));
        }

        $documents = $kb->documents()
            ->latest()
            ->paginate($request->input('per_page', 20));

        return DocumentResource::collection($documents);
    }

    /**
     * Create a text document in a bot's knowledge base.
     */
    public function store(StoreDocumentRequest $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        // Get or create knowledge base
        $kb = $bot->knowledgeBase;
        if (!$kb) {
            $kb = KnowledgeBase::create([
                'user_id' => $request->user()->id,
                'bot_id' => $bot->id,
                'name' => $bot->name . ' Knowledge Base',
            ]);
        }

        // Create document record with text content
        $document = $kb->documents()->create([
            'original_filename' => $request->input('title'),
            'content' => $request->input('content'),
            'mime_type' => 'text/plain',
            'file_size' => strlen($request->input('content')),
            'status' => 'pending',
        ]);

        // Update document count
        $kb->increment('document_count');

        // Dispatch processing job with user's ID for API key lookup
        ProcessDocument::dispatch($document, $request->user()->id);

        return response()->json([
            'message' => 'Document created successfully. Processing will begin shortly.',
            'data' => new DocumentResource($document),
        ], 201);
    }

    /**
     * Get a specific document.
     */
    public function show(Request $request, Bot $bot, Document $document): DocumentResource
    {
        $this->authorize('view', $bot);

        // Ensure document belongs to this bot's KB
        if ($document->knowledge_base_id !== $bot->knowledgeBase?->id) {
            abort(404);
        }

        return new DocumentResource($document);
    }

    /**
     * Reprocess a failed document.
     */
    public function reprocess(Request $request, Bot $bot, Document $document): JsonResponse
    {
        $this->authorize('update', $bot);

        // Ensure document belongs to this bot's KB
        if ($document->knowledge_base_id !== $bot->knowledgeBase?->id) {
            return response()->json([
                'message' => 'Document not found',
            ], 404);
        }

        if ($document->status !== 'failed') {
            return response()->json([
                'message' => 'Only failed documents can be reprocessed',
            ], 422);
        }

        // Clear existing chunks
        $document->chunks()->delete();
        $kb = $document->knowledgeBase;
        $kb->decrement('chunk_count', $document->chunk_count);

        // Reset status and dispatch job
        $document->update([
            'status' => 'pending',
            'error_message' => null,
            'chunk_count' => 0,
        ]);

        // Dispatch with user's ID for API key lookup
        ProcessDocument::dispatch($document, $request->user()->id);

        return response()->json([
            'message' => 'Document reprocessing started',
            'data' => new DocumentResource($document->fresh()),
        ]);
    }

    /**
     * Delete a document.
     */
    public function destroy(Request $request, Bot $bot, Document $document): JsonResponse
    {
        $this->authorize('update', $bot);

        // Ensure document belongs to this bot's KB
        $kb = $bot->knowledgeBase;
        if (!$kb || $document->knowledge_base_id !== $kb->id) {
            return response()->json([
                'message' => 'Document not found',
            ], 404);
        }

        // Delete file from storage if exists (for legacy file-based documents)
        if ($document->storage_path) {
            $disk = $this->getStorageDisk();
            if (Storage::disk($disk)->exists($document->storage_path)) {
                Storage::disk($disk)->delete($document->storage_path);
            }
        }

        // Update counts
        $kb->decrement('document_count');
        $kb->decrement('chunk_count', $document->chunk_count);

        // Delete document (soft delete)
        $document->delete();

        return response()->json([
            'message' => 'Document deleted successfully',
        ]);
    }

    /**
     * Determine which storage disk to use.
     */
    private function getStorageDisk(): string
    {
        // Use R2 if configured, otherwise local
        if (config('filesystems.disks.r2.key')) {
            return 'r2';
        }

        return 'local';
    }
}
