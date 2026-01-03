<?php

namespace App\Policies;

use App\Models\QuickReply;
use App\Models\User;

class QuickReplyPolicy
{
    /**
     * All authenticated users can view quick replies.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * All authenticated users can view a quick reply.
     */
    public function view(User $user, QuickReply $quickReply): bool
    {
        return $this->canAccessQuickReply($user, $quickReply);
    }

    /**
     * Only owners can create quick replies.
     */
    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    /**
     * Only the owner of the quick reply can update it.
     */
    public function update(User $user, QuickReply $quickReply): bool
    {
        return $user->isOwner() && $quickReply->user_id === $user->id;
    }

    /**
     * Only the owner of the quick reply can delete it.
     */
    public function delete(User $user, QuickReply $quickReply): bool
    {
        return $user->isOwner() && $quickReply->user_id === $user->id;
    }

    /**
     * Check if user can access quick reply (owner or assigned admin).
     */
    private function canAccessQuickReply(User $user, QuickReply $quickReply): bool
    {
        // Owner can access their own quick replies
        if ($user->isOwner() && $quickReply->user_id === $user->id) {
            return true;
        }

        // Admin can access quick replies of owners they are assigned to
        if ($user->isAdmin()) {
            return $user->assignedBots()
                ->where('bots.user_id', $quickReply->user_id)
                ->exists();
        }

        return false;
    }
}
