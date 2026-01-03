<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuickReplyRequest;
use App\Http\Resources\QuickReplyResource;
use App\Models\QuickReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class QuickReplyController extends Controller
{
    /**
     * List all quick replies for the authenticated user's owner.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QuickReply::class);

        $user = $request->user();
        $ownerId = $this->getOwnerId($user);

        $query = QuickReply::forUser($ownerId)->ordered();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        return QuickReplyResource::collection($query->get());
    }

    /**
     * Search quick replies by shortcut prefix (for autocomplete).
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QuickReply::class);

        $user = $request->user();
        $ownerId = $this->getOwnerId($user);

        $query = QuickReply::forUser($ownerId)
            ->active()
            ->ordered();

        if ($request->filled('q')) {
            $query->byShortcut($request->input('q'));
        }

        return QuickReplyResource::collection($query->limit(10)->get());
    }

    /**
     * Store a newly created quick reply.
     */
    public function store(QuickReplyRequest $request): QuickReplyResource
    {
        $this->authorize('create', QuickReply::class);

        $user = $request->user();

        $quickReply = QuickReply::create([
            'user_id' => $user->id,
            'shortcut' => $request->input('shortcut'),
            'title' => $request->input('title'),
            'content' => $request->input('content'),
            'category' => $request->input('category'),
            'sort_order' => $request->input('sort_order', 0),
            'is_active' => $request->input('is_active', true),
            'created_by' => $user->id,
        ]);

        return new QuickReplyResource($quickReply);
    }

    /**
     * Display the specified quick reply.
     */
    public function show(QuickReply $quickReply): QuickReplyResource
    {
        $this->authorize('view', $quickReply);

        return new QuickReplyResource($quickReply);
    }

    /**
     * Update the specified quick reply.
     */
    public function update(QuickReplyRequest $request, QuickReply $quickReply): QuickReplyResource
    {
        $this->authorize('update', $quickReply);

        $quickReply->update($request->validated());

        return new QuickReplyResource($quickReply);
    }

    /**
     * Remove the specified quick reply.
     */
    public function destroy(QuickReply $quickReply): Response
    {
        $this->authorize('delete', $quickReply);

        $quickReply->delete();

        return response()->noContent();
    }

    /**
     * Toggle the active status of a quick reply.
     */
    public function toggle(QuickReply $quickReply): QuickReplyResource
    {
        $this->authorize('update', $quickReply);

        $quickReply->update(['is_active' => ! $quickReply->is_active]);

        return new QuickReplyResource($quickReply);
    }

    /**
     * Reorder quick replies.
     */
    public function reorder(Request $request): Response
    {
        $this->authorize('create', QuickReply::class);

        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:quick_replies,id'],
        ]);

        $user = $request->user();
        $ids = $request->input('ids');

        foreach ($ids as $index => $id) {
            QuickReply::where('id', $id)
                ->where('user_id', $user->id)
                ->update(['sort_order' => $index]);
        }

        return response()->noContent();
    }

    /**
     * Get the owner ID for quick replies access.
     * Owners see their own, Admins see their assigned owner's.
     */
    private function getOwnerId($user): int
    {
        if ($user->isOwner()) {
            return $user->id;
        }

        // For admins, get the owner of their first assigned bot
        $assignedBot = $user->assignedBots()->first();

        return $assignedBot?->user_id ?? $user->id;
    }
}
