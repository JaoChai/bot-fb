<?php

namespace App\Http\Controllers\KnowledgeBase;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocument;
use App\Models\Bot;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\SemanticSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    /**
     * Display a listing of knowledge bases for a bot.
     */
    public function index(Request $request, Bot $bot): Response
    {
        $this->authorizeBot($bot);

        // Get knowledge bases associated with this bot through flows
        $flowKbIds = $bot->flows()
            ->with('knowledgeBases:id')
            ->get()
            ->pluck('knowledgeBases')
            ->flatten()
            ->pluck('id')
            ->unique();

        $knowledgeBases = KnowledgeBase::whereIn('id', $flowKbIds)
            ->orWhere('user_id', $bot->user_id)
            ->withCount('documents')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return Inertia::render('KnowledgeBases/Index', [
            'bot' => $bot->only(['id', 'name', 'channel_type']),
            'knowledgeBases' => $knowledgeBases,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Display a single knowledge base with its documents.
     */
    public function show(Bot $bot, KnowledgeBase $knowledgeBase): Response
    {
        $this->authorizeBot($bot);
        $this->authorizeKnowledgeBase($knowledgeBase);

        $knowledgeBase->load([
            'documents' => fn ($query) => $query->latest()->paginate(20),
        ]);

        $knowledgeBase->loadCount('documents');

        return Inertia::render('KnowledgeBases/Show', [
            'bot' => $bot->only(['id', 'name', 'channel_type']),
            'knowledgeBase' => [
                'id' => $knowledgeBase->id,
                'name' => $knowledgeBase->name,
                'description' => $knowledgeBase->description,
                'document_count' => $knowledgeBase->documents_count,
                'chunk_count' => $knowledgeBase->chunk_count,
                'embedding_model' => $knowledgeBase->embedding_model,
                'created_at' => $knowledgeBase->created_at->toISOString(),
                'updated_at' => $knowledgeBase->updated_at->toISOString(),
            ],
            'documents' => $knowledgeBase->documents()
                ->latest()
                ->paginate(20)
                ->through(fn ($doc) => [
                    'id' => $doc->id,
                    'original_filename' => $doc->original_filename,
                    'mime_type' => $doc->mime_type,
                    'file_size' => $doc->file_size,
                    'status' => $doc->status,
                    'error_message' => $doc->error_message,
                    'chunk_count' => $doc->chunk_count,
                    'created_at' => $doc->created_at->toISOString(),
                ]),
        ]);
    }

    /**
     * Store a newly created knowledge base.
     */
    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'embedding_model' => ['nullable', 'string', 'max:100'],
        ]);

        $knowledgeBase = KnowledgeBase::create([
            'user_id' => $bot->user_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'embedding_model' => $validated['embedding_model'] ?? config('services.embeddings.model', 'text-embedding-3-small'),
            'embedding_dimensions' => config('services.embeddings.dimensions', 1536),
            'document_count' => 0,
            'chunk_count' => 0,
        ]);

        return response()->json([
            'message' => 'Knowledge base created successfully',
            'data' => [
                'id' => $knowledgeBase->id,
                'name' => $knowledgeBase->name,
                'description' => $knowledgeBase->description,
                'embedding_model' => $knowledgeBase->embedding_model,
                'created_at' => $knowledgeBase->created_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Update the specified knowledge base.
     */
    public function update(Request $request, Bot $bot, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeKnowledgeBase($knowledgeBase);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $knowledgeBase->update($validated);

        return response()->json([
            'message' => 'Knowledge base updated successfully',
            'data' => [
                'id' => $knowledgeBase->id,
                'name' => $knowledgeBase->name,
                'description' => $knowledgeBase->description,
                'updated_at' => $knowledgeBase->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Remove the specified knowledge base.
     */
    public function destroy(Bot $bot, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeKnowledgeBase($knowledgeBase);

        // Delete all documents and their storage files
        foreach ($knowledgeBase->documents as $document) {
            if ($document->storage_path) {
                $this->deleteStorageFile($document->storage_path);
            }
        }

        $knowledgeBase->delete();

        return response()->json([
            'message' => 'Knowledge base deleted successfully',
        ]);
    }

    /**
     * Upload a document to the knowledge base.
     */
    public function uploadDocument(Request $request, Bot $bot, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeKnowledgeBase($knowledgeBase);

        $validated = $request->validate([
            'file' => ['required_without:content', 'file', 'max:10240', 'mimes:pdf,txt,md,doc,docx'],
            'content' => ['required_without:file', 'string', 'max:100000'],
            'title' => ['required_with:content', 'string', 'max:255'],
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $disk = $this->getStorageDisk();
            $path = $file->store('documents/' . $knowledgeBase->id, $disk);

            $document = $knowledgeBase->documents()->create([
                'filename' => basename($path),
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'storage_path' => $path,
                'status' => 'pending',
            ]);
        } else {
            // Text content upload
            $document = $knowledgeBase->documents()->create([
                'original_filename' => $validated['title'],
                'content' => $validated['content'],
                'mime_type' => 'text/plain',
                'file_size' => strlen($validated['content']),
                'status' => 'pending',
            ]);
        }

        // Update document count
        $knowledgeBase->increment('document_count');

        // Dispatch processing job
        ProcessDocument::dispatch($document, Auth::id());

        return response()->json([
            'message' => 'Document uploaded successfully. Processing will begin shortly.',
            'data' => [
                'id' => $document->id,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'status' => $document->status,
                'created_at' => $document->created_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Delete a document from the knowledge base.
     */
    public function deleteDocument(Bot $bot, KnowledgeBase $knowledgeBase, Document $document): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeKnowledgeBase($knowledgeBase);

        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            return response()->json([
                'message' => 'Document not found in this knowledge base',
            ], 404);
        }

        // Delete file from storage
        if ($document->storage_path) {
            $this->deleteStorageFile($document->storage_path);
        }

        // Update counts
        $knowledgeBase->decrement('document_count');
        $knowledgeBase->decrement('chunk_count', $document->chunk_count);

        // Soft delete document (also deletes chunks via cascade)
        $document->delete();

        return response()->json([
            'message' => 'Document deleted successfully',
        ]);
    }

    /**
     * Retry processing a failed document.
     */
    public function retryDocument(Request $request, Bot $bot, KnowledgeBase $knowledgeBase, Document $document): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeKnowledgeBase($knowledgeBase);

        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            return response()->json([
                'message' => 'Document not found in this knowledge base',
            ], 404);
        }

        if ($document->status !== 'failed') {
            return response()->json([
                'message' => 'Only failed documents can be retried',
            ], 422);
        }

        // Clear existing chunks and reset counts
        $chunkCount = $document->chunk_count;
        $document->chunks()->delete();
        $knowledgeBase->decrement('chunk_count', $chunkCount);

        // Reset document status
        $document->update([
            'status' => 'pending',
            'error_message' => null,
            'chunk_count' => 0,
        ]);

        // Dispatch processing job
        ProcessDocument::dispatch($document, Auth::id());

        return response()->json([
            'message' => 'Document reprocessing started',
            'data' => [
                'id' => $document->id,
                'status' => $document->status,
            ],
        ]);
    }

    /**
     * Perform semantic search on the knowledge base.
     */
    public function search(Request $request, Bot $bot, KnowledgeBase $knowledgeBase, SemanticSearchService $searchService): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeKnowledgeBase($knowledgeBase);

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
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
            // Get API key from user settings or fallback to env
            $apiKey = Auth::user()->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

            $results = $searchService->search(
                knowledgeBaseId: $knowledgeBase->id,
                query: $validated['query'],
                limit: $validated['limit'] ?? 5,
                threshold: $validated['threshold'] ?? null,
                apiKey: $apiKey
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

    /**
     * Authorize that the current user owns the bot.
     */
    protected function authorizeBot(Bot $bot): void
    {
        $user = Auth::user();

        // Check if user is the owner
        if ($bot->user_id === $user->id) {
            return;
        }

        // Check if user is an admin of this bot
        if ($bot->admins()->where('users.id', $user->id)->exists()) {
            return;
        }

        abort(403, 'You do not have access to this bot');
    }

    /**
     * Authorize that the current user owns the knowledge base.
     */
    protected function authorizeKnowledgeBase(KnowledgeBase $knowledgeBase): void
    {
        $user = Auth::user();

        if ($knowledgeBase->user_id !== $user->id) {
            abort(403, 'You do not have access to this knowledge base');
        }
    }

    /**
     * Delete a file from storage.
     */
    protected function deleteStorageFile(string $path): void
    {
        $disk = $this->getStorageDisk();

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * Get the storage disk to use for document files.
     */
    protected function getStorageDisk(): string
    {
        // Use R2 if configured, otherwise local
        if (config('filesystems.disks.r2.key')) {
            return 'r2';
        }

        return 'local';
    }
}
