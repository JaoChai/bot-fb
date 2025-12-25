<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeBase\StoreKnowledgeBaseRequest;
use App\Http\Requests\KnowledgeBase\UpdateKnowledgeBaseRequest;
use App\Http\Resources\KnowledgeBaseResource;
use App\Models\Bot;
use App\Models\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeBaseController extends Controller
{
    /**
     * Get or create the knowledge base for a bot.
     */
    public function show(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $kb = $bot->knowledgeBase;

        if (!$kb) {
            // Auto-create KB for bot if it doesn't exist
            $kb = KnowledgeBase::create([
                'user_id' => $request->user()->id,
                'bot_id' => $bot->id,
                'name' => $bot->name . ' Knowledge Base',
                'description' => 'Knowledge base for ' . $bot->name,
            ]);
        }

        return response()->json([
            'data' => new KnowledgeBaseResource($kb->load('documents')),
        ]);
    }

    /**
     * Update knowledge base settings.
     */
    public function update(UpdateKnowledgeBaseRequest $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $kb = $bot->knowledgeBase;

        if (!$kb) {
            return response()->json([
                'message' => 'Knowledge base not found',
            ], 404);
        }

        $kb->update($request->validated());

        return response()->json([
            'message' => 'Knowledge base updated successfully',
            'data' => new KnowledgeBaseResource($kb->fresh()),
        ]);
    }
}
