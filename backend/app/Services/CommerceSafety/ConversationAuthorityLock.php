<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\Conversation;

final class ConversationAuthorityLock
{
    /** Call inside the local transaction, before locking any conversation. */
    public static function acquire(int $botId, int $conversationId): ?Conversation
    {
        // Shared entry mutex also orders proof/manual/settlement transactions.
        Bot::query()->whereKey($botId)->lockForUpdate()->firstOrFail();
        $candidate = Conversation::query()->where('bot_id', $botId)->find($conversationId);
        if ($candidate === null) {
            return null;
        }

        // Discover the entire entitlement source set before taking any row lock.
        return Conversation::query()->where('bot_id', $botId)
            ->where(function ($query) use ($candidate): void {
                $query->whereKey($candidate->id);
                if ($candidate->customer_profile_id !== null) {
                    $query->orWhere('customer_profile_id', $candidate->customer_profile_id);
                }
            })
            ->orderBy('id')->lockForUpdate()->get()->firstWhere('id', $conversationId);
    }
}
