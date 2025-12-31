<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    /**
     * Search users by email.
     * Only owners can search for users to add as admins.
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only owners can search for users
        if (!$user->isOwner()) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $validated = $request->validate([
            'email' => 'required|string|min:3',
        ]);

        $email = $validated['email'];

        // Search users by email (case-insensitive, partial match)
        $users = User::where('email', 'ILIKE', "%{$email}%")
            ->where('id', '!=', $user->id) // Exclude current user
            ->select(['id', 'name', 'email', 'role'])
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $users,
        ]);
    }
}
