<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationUpdated;
use App\Http\Resources\ConversationResource;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationAssignmentController extends BaseConversationController
{
    public function __construct(ConversationCacheService $cacheService)
    {
        parent::__construct($cacheService);
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
            title: $isHandover ? 'Handover Mode' : 'Handover Mode',
            description: ": {$customerName}",
            botId: $bot->id,
            metadata: ['conversation_id' => $conversation->id]
        );

        // Load relationships for broadcast and response
        $conversation->load(['customerProfile', 'assignedUser']);

        // Invalidate stats cache (status changed to handover or active)
        $this->cacheService->invalidateStats($bot->id);

        // Broadcast the update for real-time sync
        broadcast(new ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => $isHandover ? 'Handover mode enabled' : 'Bot mode enabled',
            'data' => new ConversationResource($conversation),
        ]);
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
        $targetUser = User::find($validated['user_id']);
        if (! $targetUser->canAccessBot($bot)) {
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

        if (! $isOwner && ! $isAssignedUser) {
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
}
