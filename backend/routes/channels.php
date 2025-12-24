<?php

use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/**
 * Private channel for a specific conversation
 * Authorized for: bot owner OR assigned agent
 */
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::with('bot')->find($conversationId);

    if (! $conversation) {
        return false;
    }

    // Allow if user owns the bot
    if ($conversation->bot->user_id === $user->id) {
        return true;
    }

    // Allow if user is assigned to this conversation (handover mode)
    if ($conversation->assigned_user_id === $user->id) {
        return true;
    }

    return false;
});

/**
 * Private channel for all conversations of a bot
 * Authorized for: bot owner only
 */
Broadcast::channel('bot.{botId}', function ($user, $botId) {
    $bot = Bot::find($botId);

    if (! $bot) {
        return false;
    }

    return $bot->user_id === $user->id;
});

/**
 * Presence channel for bot dashboard
 * Returns user info for presence tracking
 */
Broadcast::channel('bot.{botId}.presence', function ($user, $botId) {
    $bot = Bot::find($botId);

    if (! $bot || $bot->user_id !== $user->id) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});

/**
 * Private channel for admin notifications
 * Authorized for: any authenticated user (their own notifications)
 */
Broadcast::channel('user.{userId}.notifications', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
