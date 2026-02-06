<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TagService
{
    /**
     * Get all unique tags used in bot conversations (cached).
     */
    public function getAllTags(Bot $bot): array
    {
        $cacheKey = "bot:{$bot->id}:conversation:tags";

        $tags = Cache::remember($cacheKey, 60, fn () => DB::select('
            SELECT DISTINCT jsonb_array_elements_text(tags) as tag
            FROM conversations
            WHERE bot_id = ?
                AND deleted_at IS NULL
                AND tags IS NOT NULL
                AND jsonb_array_length(tags) > 0
            ORDER BY tag
        ', [$bot->id]));

        return array_column($tags, 'tag');
    }

    /**
     * Add tags to a conversation.
     */
    public function addTags(Conversation $conversation, array $tags): array
    {
        $currentTags = $conversation->tags ?? [];
        $newTags = array_unique(array_merge($currentTags, $tags));

        $conversation->update(['tags' => array_values($newTags)]);

        $this->invalidateTagsCache($conversation->bot_id);

        return $newTags;
    }

    /**
     * Remove a tag from a conversation.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function removeTag(Conversation $conversation, string $tag): array
    {
        $currentTags = $conversation->tags ?? [];
        $filteredTags = array_values(array_filter($currentTags, fn ($t) => $t !== $tag));

        if (count($filteredTags) === count($currentTags)) {
            abort(404, 'Tag not found');
        }

        $conversation->update(['tags' => $filteredTags]);

        $this->invalidateTagsCache($conversation->bot_id);

        return $filteredTags;
    }

    /**
     * Bulk add tags to multiple conversations.
     *
     * @throws \InvalidArgumentException If some conversations don't belong to the bot
     */
    public function bulkAddTags(Bot $bot, array $conversationIds, array $tags): int
    {
        $conversationIds = array_values(array_unique($conversationIds));

        if (empty($conversationIds) || empty($tags)) {
            return 0;
        }

        return DB::transaction(function () use ($bot, $conversationIds, $tags) {
            $validCount = $bot->conversations()
                ->whereIn('id', $conversationIds)
                ->lockForUpdate()
                ->count();

            if ($validCount !== count($conversationIds)) {
                throw new \InvalidArgumentException('Some conversations do not belong to this bot');
            }

            $newTagsJson = json_encode(array_values($tags), JSON_THROW_ON_ERROR);
            $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));

            // SECURITY: $placeholders is always '?,?,?' from array_fill - never interpolate $conversationIds directly
            $updated = DB::update("
                UPDATE conversations
                SET tags = (
                    SELECT COALESCE(jsonb_agg(DISTINCT elem), '[]'::jsonb)
                    FROM jsonb_array_elements_text(
                        COALESCE(tags, '[]'::jsonb) || ?::jsonb
                    ) AS elem
                ),
                updated_at = NOW()
                WHERE bot_id = ?
                  AND deleted_at IS NULL
                  AND id IN ({$placeholders})
            ", array_merge(
                [$newTagsJson, $bot->id],
                $conversationIds
            ));

            $this->invalidateTagsCache($bot->id);

            return $updated;
        });
    }

    /**
     * Invalidate tags cache for a bot.
     */
    private function invalidateTagsCache(int $botId): void
    {
        Cache::forget("bot:{$botId}:conversation:tags");
    }
}
