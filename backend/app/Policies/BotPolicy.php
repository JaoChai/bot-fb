<?php

namespace App\Policies;

use App\Models\Bot;
use App\Models\User;

class BotPolicy
{
    /**
     * Determine if the user can view the bot.
     */
    public function view(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    /**
     * Determine if the user can update the bot.
     */
    public function update(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    /**
     * Determine if the user can delete the bot.
     */
    public function delete(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }
}
