<?php

namespace App\Services\Webhook\LINE;

use App\Models\Bot;
use Illuminate\Support\Facades\Log;

/**
 * Processes LINE message events.
 *
 * Part of Phase 6 refactoring - extracts message processing from ProcessLINEWebhook.
 *
 * Handles:
 * - Text messages
 * - Image/Video/Audio messages (via LINEMediaProcessor)
 * - Sticker messages
 * - Location messages
 * - File messages
 *
 * TODO: This is a foundation class. Full implementation requires extracting
 * logic from ProcessLINEWebhook::processEvent() method (~500 lines).
 */
class LINEMessageProcessor
{
    /**
     * Process a message event.
     */
    public function process(Bot $bot, array $event): void
    {
        $messageType = $event['message']['type'] ?? 'unknown';
        $userId = $event['source']['userId'] ?? null;

        if (! $userId) {
            Log::warning('LINEMessageProcessor: Message without userId', [
                'bot_id' => $bot->id,
            ]);

            return;
        }

        if (config('webhooks.handlers.line.debug_logging')) {
            Log::debug('LINEMessageProcessor: Processing message', [
                'bot_id' => $bot->id,
                'user_id' => $userId,
                'message_type' => $messageType,
            ]);
        }

        match ($messageType) {
            'text' => $this->processText($bot, $event),
            'image' => $this->processMedia($bot, $event, 'image'),
            'video' => $this->processMedia($bot, $event, 'video'),
            'audio' => $this->processMedia($bot, $event, 'audio'),
            'file' => $this->processMedia($bot, $event, 'file'),
            'sticker' => $this->processSticker($bot, $event),
            'location' => $this->processLocation($bot, $event),
            default => $this->processUnknown($bot, $event, $messageType),
        };
    }

    /**
     * Process a postback event as message.
     */
    public function processPostback(Bot $bot, array $event, string $postbackData): void
    {
        Log::debug('LINEMessageProcessor: Processing postback as message', [
            'bot_id' => $bot->id,
            'postback_data' => $postbackData,
        ]);

        // TODO: Implement postback to message conversion
        // This may trigger flow actions or AI response based on postback data
    }

    /**
     * Process text message.
     */
    private function processText(Bot $bot, array $event): void
    {
        $text = $event['message']['text'] ?? '';

        Log::debug('LINEMessageProcessor: Text message received', [
            'bot_id' => $bot->id,
            'text_length' => strlen($text),
        ]);

        // TODO: Full implementation from ProcessLINEWebhook
        // 1. Find/create conversation
        // 2. Save message to database
        // 3. Check aggregation settings
        // 4. Generate AI response if needed
        // 5. Broadcast events
    }

    /**
     * Process media message (image, video, audio, file).
     */
    private function processMedia(Bot $bot, array $event, string $mediaType): void
    {
        $messageId = $event['message']['id'] ?? null;

        Log::debug('LINEMessageProcessor: Media message received', [
            'bot_id' => $bot->id,
            'media_type' => $mediaType,
            'message_id' => $messageId,
        ]);

        // TODO: Full implementation from ProcessLINEWebhook
        // 1. Download media from LINE
        // 2. Upload to storage
        // 3. Save message with media_url
    }

    /**
     * Process sticker message.
     */
    private function processSticker(Bot $bot, array $event): void
    {
        $stickerId = $event['message']['stickerId'] ?? null;
        $packageId = $event['message']['packageId'] ?? null;

        Log::debug('LINEMessageProcessor: Sticker message received', [
            'bot_id' => $bot->id,
            'sticker_id' => $stickerId,
            'package_id' => $packageId,
        ]);

        // TODO: Full implementation - may trigger sticker auto-reply
    }

    /**
     * Process location message.
     */
    private function processLocation(Bot $bot, array $event): void
    {
        $latitude = $event['message']['latitude'] ?? null;
        $longitude = $event['message']['longitude'] ?? null;
        $address = $event['message']['address'] ?? null;

        Log::debug('LINEMessageProcessor: Location message received', [
            'bot_id' => $bot->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        // TODO: Full implementation
    }

    /**
     * Process unknown message type.
     */
    private function processUnknown(Bot $bot, array $event, string $messageType): void
    {
        Log::warning('LINEMessageProcessor: Unknown message type', [
            'bot_id' => $bot->id,
            'message_type' => $messageType,
        ]);
    }
}
