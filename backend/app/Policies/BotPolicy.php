<?php

namespace App\Policies;

use App\Models\Bot;
use App\Models\User;

class BotPolicy
{
    /**
     * Determine if the user can view the bot.
     * Owner can view their own bots, Admin can view assigned bots.
     */
    public function view(User $user, Bot $bot): bool
    {
        return $user->canAccessBot($bot);
    }

    /**
     * Determine if the user can update the bot.
     * Only the owner can update bot settings.
     */
    public function update(User $user, Bot $bot): bool
    {
        return $user->isOwner() && $user->id === $bot->user_id;
    }

    /**
     * Determine if the user can delete the bot.
     * Only the owner can delete bots.
     */
    public function delete(User $user, Bot $bot): bool
    {
        return $user->isOwner() && $user->id === $bot->user_id;
    }

    /**
     * Determine if the user can manage admins for this bot.
     * Only the owner can add/remove admins.
     */
    public function manageAdmins(User $user, Bot $bot): bool
    {
        return $user->isOwner() && $user->id === $bot->user_id;
    }
}
