<?php

namespace App\Services\Webhook\Channels\Facebook;

use App\Models\Bot;
use App\Services\Webhook\WebhookContext;

/**
 * Maps a Facebook Messenger messaging event into a channel-agnostic
 * WebhookContext. Pure mapper: no network, no DB, no service resolution.
 *
 * Mapping rules are copied verbatim from ProcessFacebookWebhook
 * (processMessagingEvent / handleMessage / handlePostback /
 * mapAttachmentType / generateAttachmentPlaceholder).
 *
 * map() accepts a SINGLE messaging event (one entry of
 * $payload['entry'][N]['messaging']) — the exact array the job passes to
 * processMessagingEvent().
 *
 * Metadata keys set on the returned context (consumed by Task 7/8):
 *   sender_id       -> event['sender']['id']       (the PSID)
 *   recipient_id    -> event['recipient']['id']    (page id)
 *   mid             -> message['mid']              (message events only)
 *   media_url       -> attachment payload url       (null for text)
 *   media_metadata  -> ['attachment_type','sticker_id','coordinates'] or null
 *   postback_payload-> postback['payload']          (postback events only)
 *   postback_title  -> postback['title']            (postback events only)
 */
class FacebookEventMapper
{
    /**
     * Map a single messaging event into a WebhookContext.
     *
     * Returns null for ignorable events: missing sender/recipient, echo
     * messages, and non-message/non-postback events (reads, deliveries,
     * referrals, reactions, opt-ins) — matching the job's early-return
     * conditions.
     *
     * @param  array<string, mixed>  $event  a messaging event
     */
    public function map(array $event, Bot $bot): ?WebhookContext
    {
        $senderId = $event['sender']['id'] ?? null;
        $recipientId = $event['recipient']['id'] ?? null;

        if (! $senderId || ! $recipientId) {
            return null;
        }

        // Ignore echo messages (messages sent by the page itself)
        if (isset($event['message']['is_echo']) && $event['message']['is_echo']) {
            return null;
        }

        if (isset($event['message'])) {
            $context = $this->mapMessage($event['message'], $bot, $senderId, $recipientId);
        } elseif (isset($event['postback'])) {
            $context = $this->mapPostback($event['postback'], $bot, $senderId, $recipientId);
        } else {
            return null;
        }

        return $context;
    }

    /**
     * Map a message event.
     *
     * @param  array<string, mixed>  $message
     */
    protected function mapMessage(array $message, Bot $bot, string $senderId, string $recipientId): WebhookContext
    {
        $mid = $message['mid'] ?? null;
        $text = $message['text'] ?? null;
        $attachments = $message['attachments'] ?? [];

        $messageType = 'text';
        $mediaUrl = null;
        $mediaMetadata = null;

        if (! empty($attachments)) {
            $attachment = $attachments[0]; // Process first attachment
            $attachmentType = $attachment['type'] ?? 'unknown';
            $payload = $attachment['payload'] ?? [];

            $messageType = $this->mapAttachmentType($attachmentType);
            $mediaUrl = $payload['url'] ?? null;
            $mediaMetadata = [
                'attachment_type' => $attachmentType,
                'sticker_id' => $payload['sticker_id'] ?? null,
                'coordinates' => $payload['coordinates'] ?? null,
            ];

            // Generate placeholder text for non-text messages
            if (! $text) {
                $text = $this->generateAttachmentPlaceholder($attachmentType, $mediaMetadata);
            }
        }

        $context = new WebhookContext(
            $bot,
            [
                'type' => 'message',
                'message' => [
                    'text' => $text,
                    'type' => $messageType,
                ],
            ],
            'facebook',
        );

        $context->metadata = [
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'mid' => $mid,
            'media_url' => $mediaUrl,
            'media_metadata' => $mediaMetadata,
        ];

        return $context;
    }

    /**
     * Map a postback event.
     *
     * @param  array<string, mixed>  $postback
     */
    protected function mapPostback(array $postback, Bot $bot, string $senderId, string $recipientId): WebhookContext
    {
        $payload = $postback['payload'] ?? '';
        $title = $postback['title'] ?? '';

        // Use title as message content, fall back to payload
        $content = $title ?: $payload;

        $context = new WebhookContext(
            $bot,
            [
                'type' => 'postback',
                'text' => $content,
                'message' => ['type' => 'postback'],
            ],
            'facebook',
        );

        $context->metadata = [
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'postback_payload' => $payload,
            'postback_title' => $title,
        ];

        return $context;
    }

    /**
     * Map Facebook attachment type to our message type.
     * Copied verbatim from ProcessFacebookWebhook::mapAttachmentType().
     */
    protected function mapAttachmentType(string $attachmentType): string
    {
        return match ($attachmentType) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            'file' => 'file',
            'location' => 'location',
            'fallback' => 'text', // URL previews, shared posts, etc.
            default => 'attachment',
        };
    }

    /**
     * Generate placeholder content for attachment messages.
     * Copied verbatim from ProcessFacebookWebhook::generateAttachmentPlaceholder().
     *
     * @param  array<string, mixed>|null  $metadata
     */
    protected function generateAttachmentPlaceholder(string $type, ?array $metadata): string
    {
        return match ($type) {
            'image' => '[Image]',
            'video' => '[Video]',
            'audio' => '[Audio]',
            'file' => '[File]',
            'location' => isset($metadata['coordinates'])
                ? "[Location: {$metadata['coordinates']['lat']}, {$metadata['coordinates']['long']}]"
                : '[Location shared]',
            'sticker' => isset($metadata['sticker_id'])
                ? "[Sticker #{$metadata['sticker_id']}]"
                : '[Sticker]',
            'fallback' => '[Shared content]',
            default => '[Attachment]',
        };
    }
}
