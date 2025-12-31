<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminBotAssignment;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * List all admins for a bot.
     */
    public function index(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('manageAdmins', $bot);

        $withCounts = $request->boolean('with_counts', false);

        $query = $bot->adminAssignments()->with('user');

        if ($withCounts) {
            // Get active conversation counts for each admin
            $admins = $query->get()->map(function ($assignment) use ($bot) {
                $assignment->active_conversations_count = Conversation::where('bot_id', $bot->id)
                    ->where('assigned_user_id', $assignment->user_id)
                    ->where('status', 'active')
                    ->count();
                return $assignment;
            });

            return response()->json([
                'data' => $admins,
            ]);
        }

        $admins = $query->get();

        return response()->json([
            'data' => $admins,
        ]);
    }

    /**
     * Add an admin to a bot.
     */
    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('manageAdmins', $bot);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        // Check if user is not already an admin
        $exists = AdminBotAssignment::where('bot_id', $bot->id)
            ->where('user_id', $validated['user_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'User is already an admin for this bot',
            ], 422);
        }

        // Check if user is not the owner
        if ($validated['user_id'] === $bot->user_id) {
            return response()->json([
                'message' => 'Cannot add bot owner as admin',
            ], 422);
        }

        $assignment = AdminBotAssignment::create([
            'user_id' => $validated['user_id'],
            'bot_id' => $bot->id,
            'assigned_by' => $request->user()->id,
        ]);

        $assignment->load('user');

        return response()->json([
            'message' => 'Admin added successfully',
            'data' => $assignment,
        ], 201);
    }

    /**
     * Remove an admin from a bot.
     */
    public function destroy(Request $request, Bot $bot, User $user): JsonResponse
    {
        $this->authorize('manageAdmins', $bot);

        $deleted = AdminBotAssignment::where('bot_id', $bot->id)
            ->where('user_id', $user->id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'Admin not found for this bot',
            ], 404);
        }

        // Optionally unassign any conversations assigned to this admin
        Conversation::where('bot_id', $bot->id)
            ->where('assigned_user_id', $user->id)
            ->update(['assigned_user_id' => null]);

        return response()->json([
            'message' => 'Admin removed successfully',
        ]);
    }
}
