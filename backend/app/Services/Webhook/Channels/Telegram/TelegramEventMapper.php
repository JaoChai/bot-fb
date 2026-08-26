<?php

namespace App\Services\Webhook\Channels\Telegram;

use App\Models\Bot;
use App\Services\TelegramService;
use App\Services\Webhook\WebhookContext;

/**
 * Maps a raw Telegram Bot API update into a channel-agnostic WebhookContext.
 * Pure mapper: no network, no DB, no service resolution inside map().
 *
 * Reuses the public pure parsing helpers from TelegramService
 * (parseUpdate / extractFileId / extractMediaMetadata) so the mapping stays
 * byte-for-byte consistent with the running job. The service instance is
 * constructed inline (constructorless) and only its pure methods are called.
 *
 * Mapping rules copied from ProcessTelegramWebhook
 * (processUpdate / mapMessageType / generateMediaPlaceholder).
 *
 * map() accepts the FULL raw update (same array the job receives as
 * $this->update).
 *
 * Metadata keys set on the returned context (consumed by Task 7/8):
 *   update_id  -> update['update_id']
 *   message_id -> (string) parsed message_id
 *   chat_id    -> (string) parsed chat id
 *   user_id    -> (string) parsed sender id ('' when absent)
 *   chat_type  -> parsed chat type ('private' default)
 *   chat_title -> parsed chat title (null for private)
 *   username   -> parsed username (null when absent)
 *   first_name -> parsed sender first name (null when absent)
 *   last_name  -> parsed sender last name (null when absent)
 *   reply_to_message_id -> parsed reply target (null when absent)
 *   file_id    -> extracted media file id (null for text)
 *   caption    -> parsed text/caption (null when absent)
 *   placeholder-> generateMediaPlaceholder() result (null for text)
 *   media_url  -> null (downloading is a later pipeline step)
 *   mime_type  -> null (downloading is a later pipeline step)
 *   media_metadata -> TelegramService::extractMediaMetadata() result (null for text)
 */
class TelegramEventMapper
{
    /**
     * Map a raw Telegram update into a WebhookContext.
     *
     * Returns null for non-message updates (my_chat_member, callback_query,
     * inline_query, etc.) or updates without a chat id — matching the job's
     * `type === 'unknown' || ! chat_id` early-return.
     *
     * @param  array<string, mixed>  $update  the raw update
     */
    public function map(array $update, Bot $bot): ?WebhookContext
    {
        $telegramService = new TelegramService;
        $parsed = $telegramService->parseUpdate($update);

        // Only process message updates (mirrors processUpdate early-return)
        if ($parsed['type'] === 'unknown' || ! $parsed['chat_id']) {
            return null;
        }

        // Process media if present (pure portion: file id + metadata)
        $rawMessage = $parsed['raw_message'] ?? [];
        $fileId = $parsed['type'] === 'text' ? null : $telegramService->extractFileId($rawMessage);
        $mediaMetadata = $parsed['type'] === 'text' ? null : $telegramService->extractMediaMetadata($rawMessage);

        // Determine message content
        $content = $parsed['text'];
        $placeholder = null;
        if (! $content && $parsed['type'] !== 'text') {
            // Generate placeholder for non-text messages
            $placeholder = $this->generateMediaPlaceholder($parsed['type'], ['metadata' => $mediaMetadata]);
            $content = $placeholder;
        }

        $context = new WebhookContext(
            $bot,
            [
                'type' => 'message',
                'message' => [
                    'text' => $content,
                    'type' => $parsed['type'],
                ],
            ],
            'telegram',
        );

        $context->metadata = [
            'update_id' => $parsed['update_id'] ?? null,
            'message_id' => $parsed['message_id'] !== null ? (string) $parsed['message_id'] : null,
            'chat_id' => $parsed['chat_id'],
            'user_id' => $parsed['user_id'] ?? null,
            'chat_type' => $parsed['chat_type'] ?? null,
            'chat_title' => $parsed['chat_title'] ?? null,
            'username' => $parsed['username'] ?? null,
            'first_name' => $parsed['first_name'] ?? null,
            'last_name' => $parsed['last_name'] ?? null,
            'reply_to_message_id' => $parsed['reply_to_message_id'] ?? null,
            'file_id' => $fileId,
            'caption' => $parsed['text'],
            'placeholder' => $placeholder,
            'media_url' => null, // resolved by a later pipeline step
            'mime_type' => null, // resolved by a later pipeline step
            'media_metadata' => $mediaMetadata,
        ];

        return $context;
    }

    /**
     * Map Telegram message type to our message type.
     * Copied verbatim from ProcessTelegramWebhook::mapMessageType().
     */
    protected function mapMessageType(string $telegramType): string
    {
        return match ($telegramType) {
            'photo' => 'image',
            'video', 'video_note', 'animation' => 'video',
            'voice' => 'voice',
            'audio' => 'audio',
            'file' => 'file',
            'sticker' => 'sticker',
            'location' => 'location',
            'contact' => 'contact',
            'poll' => 'poll',
            default => 'text',
        };
    }

    /**
     * Generate placeholder content for non-text messages.
     * Copied verbatim from ProcessTelegramWebhook::generateMediaPlaceholder().
     *
     * @param  array<string, mixed>  $mediaData
     */
    protected function generateMediaPlaceholder(string $type, array $mediaData): string
    {
        $metadata = $mediaData['metadata'] ?? [];

        return match ($type) {
            'photo' => '[Photo]',
            'video', 'video_note', 'animation' => '[Video]',
            'voice' => '[Voice message]',
            'audio' => isset($metadata['title'])
                ? "[Audio: {$metadata['title']}]"
                : '[Audio]',
            'file' => isset($metadata['file_name'])
                ? "[File: {$metadata['file_name']}]"
                : '[File]',
            'sticker' => isset($metadata['emoji'])
                ? "[Sticker {$metadata['emoji']}]"
                : '[Sticker]',
            'location' => isset($metadata['title'])
                ? "[Location: {$metadata['title']}]"
                : '[Location shared]',
            'contact' => isset($metadata['first_name'])
                ? "[Contact: {$metadata['first_name']}]"
                : '[Contact shared]',
            'poll' => isset($metadata['question'])
                ? "[Poll: {$metadata['question']}]"
                : '[Poll]',
            default => '[Media]',
        };
    }
}
