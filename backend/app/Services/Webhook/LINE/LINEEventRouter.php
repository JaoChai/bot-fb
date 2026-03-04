<?php

namespace App\Services\Webhook\LINE;

use App\Models\Bot;
use Illuminate\Support\Facades\Log;

/**
 * Routes LINE webhook events to appropriate handlers.
 *
 * Part of Phase 6 refactoring - extracts event routing logic from ProcessLINEWebhook.
 *
 * Supported event types:
 * - message: Text, image, video, audio, file, sticker, location messages
 * - follow: User follows bot
 * - unfollow: User unfollows bot
 * - join: Bot joins group/room
 * - leave: Bot leaves group/room
 * - postback: Button postback events
 *
 * @see https://developers.line.biz/en/reference/messaging-api/#webhook-event-objects
 */
class LINEEventRouter
{
    public function __construct(
        private LINEMessageProcessor $messageProcessor,
        private ?LINEProfileSyncHandler $profileSyncHandler = null
    ) {}

    /**
     * Route an event to the appropriate handler.
     */
    public function route(Bot $bot, array $event): void
    {
        $eventType = $event['type'] ?? 'unknown';

        if (config('webhooks.handlers.line.debug_logging')) {
            Log::debug('LINEEventRouter: Routing event', [
                'bot_id' => $bot->id,
                'event_type' => $eventType,
                'source_type' => $event['source']['type'] ?? 'unknown',
            ]);
        }

        match ($eventType) {
            'message' => $this->handleMessage($bot, $event),
            'follow' => $this->handleFollow($bot, $event),
            'unfollow' => $this->handleUnfollow($bot, $event),
            'join' => $this->handleJoin($bot, $event),
            'leave' => $this->handleLeave($bot, $event),
            'postback' => $this->handlePostback($bot, $event),
            default => $this->handleUnknown($bot, $event, $eventType),
        };
    }

    /**
     * Handle message events (text, image, video, etc.)
     */
    private function handleMessage(Bot $bot, array $event): void
    {
        $this->messageProcessor->process($bot, $event);
    }

    /**
     * Handle follow events (user starts following bot)
     */
    private function handleFollow(Bot $bot, array $event): void
    {
        $userId = $event['source']['userId'] ?? null;

        if (! $userId) {
            Log::warning('LINEEventRouter: Follow event without userId', [
                'bot_id' => $bot->id,
            ]);

            return;
        }

        // Sync profile when user follows
        if ($this->profileSyncHandler) {
            $this->profileSyncHandler->syncProfile($bot, $userId);
        }

        Log::info('LINEEventRouter: User followed bot', [
            'bot_id' => $bot->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Handle unfollow events (user blocks or unfollows bot)
     */
    private function handleUnfollow(Bot $bot, array $event): void
    {
        $userId = $event['source']['userId'] ?? null;

        Log::info('LINEEventRouter: User unfollowed bot', [
            'bot_id' => $bot->id,
            'user_id' => $userId,
        ]);

        // TODO: Mark customer profile as inactive
    }

    /**
     * Handle join events (bot joins group/room)
     */
    private function handleJoin(Bot $bot, array $event): void
    {
        $sourceType = $event['source']['type'] ?? 'unknown';
        $sourceId = $event['source'][$sourceType.'Id'] ?? null;

        Log::info('LINEEventRouter: Bot joined group/room', [
            'bot_id' => $bot->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    /**
     * Handle leave events (bot kicked from group/room)
     */
    private function handleLeave(Bot $bot, array $event): void
    {
        $sourceType = $event['source']['type'] ?? 'unknown';
        $sourceId = $event['source'][$sourceType.'Id'] ?? null;

        Log::info('LINEEventRouter: Bot left group/room', [
            'bot_id' => $bot->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);

        // TODO: Close related conversations
    }

    /**
     * Handle postback events (button clicks, datetime picker, etc.)
     */
    private function handlePostback(Bot $bot, array $event): void
    {
        $postbackData = $event['postback']['data'] ?? '';

        if (config('webhooks.handlers.line.debug_logging')) {
            Log::debug('LINEEventRouter: Postback event', [
                'bot_id' => $bot->id,
                'data' => $postbackData,
            ]);
        }

        // TODO: Implement postback handler
        // For now, treat as message with postback data
        $this->messageProcessor->processPostback($bot, $event, $postbackData);
    }

    /**
     * Handle unknown event types
     */
    private function handleUnknown(Bot $bot, array $event, string $eventType): void
    {
        Log::warning('LINEEventRouter: Unknown event type', [
            'bot_id' => $bot->id,
            'event_type' => $eventType,
            'event' => $event,
        ]);
    }
}
