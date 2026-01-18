<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeBase\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Traits\ApiResponseTrait;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    use ApiResponseTrait;
    /**
     * List all documents for a knowledge base.
     */
    public function index(Request $request, KnowledgeBase $knowledgeBase): AnonymousResourceCollection
    {
        $this->authorize('view', $knowledgeBase);

        $documents = $knowledgeBase->documents()
            ->latest()
            ->paginate($request->input('per_page', 20));

        return DocumentResource::collection($documents);
    }

    /**
     * Create a text document in a knowledge base.
     */
    public function store(StoreDocumentRequest $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorize('update', $knowledgeBase);

        // Create document record with text content
        $document = $knowledgeBase->documents()->create([
            'original_filename' => $request->input('title'),
            'content' => $request->input('content'),
            'mime_type' => 'text/plain',
            'file_size' => strlen($request->input('content')),
            'status' => 'pending',
        ]);

        // Update document count
        $knowledgeBase->increment('document_count');

        // Dispatch processing job with user's ID for API key lookup
        ProcessDocument::dispatch($document, $request->user()->id);

        return $this->created(new DocumentResource($document), 'Document created successfully. Processing will begin shortly.');
    }

    /**
     * Get a specific document.
     */
    public function show(Request $request, KnowledgeBase $knowledgeBase, Document $document): DocumentResource
    {
        $this->authorize('view', $knowledgeBase);

        // Ensure document belongs to this KB
        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            abort(404);
        }

        return new DocumentResource($document);
    }

    /**
     * Reprocess a failed document.
     */
    public function reprocess(Request $request, KnowledgeBase $knowledgeBase, Document $document): JsonResponse
    {
        $this->authorize('update', $knowledgeBase);

        // Ensure document belongs to this KB
        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            return $this->notFound('Document not found');
        }

        if ($document->status !== 'failed') {
            return $this->validationError('Only failed documents can be reprocessed');
        }

        // Clear existing chunks
        $document->chunks()->delete();
        $knowledgeBase->decrement('chunk_count', $document->chunk_count);

        // Reset status and dispatch job
        $document->update([
            'status' => 'pending',
            'error_message' => null,
            'chunk_count' => 0,
        ]);

        // Dispatch with user's ID for API key lookup
        ProcessDocument::dispatch($document, $request->user()->id);

        return $this->success(new DocumentResource($document->fresh()), 'Document reprocessing started');
    }

    /**
     * Delete a document.
     */
    public function destroy(Request $request, KnowledgeBase $knowledgeBase, Document $document): JsonResponse
    {
        $this->authorize('update', $knowledgeBase);

        // Ensure document belongs to this KB
        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            return $this->notFound('Document not found');
        }

        // Delete file from storage if exists (for legacy file-based documents)
        if ($document->storage_path) {
            $disk = $this->getStorageDisk();
            if (Storage::disk($disk)->exists($document->storage_path)) {
                Storage::disk($disk)->delete($document->storage_path);
            }
        }

        // Update counts
        $knowledgeBase->decrement('document_count');
        $knowledgeBase->decrement('chunk_count', $document->chunk_count);

        // Delete document (soft delete)
        $document->delete();

        return $this->success(null, 'Document deleted successfully');
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
