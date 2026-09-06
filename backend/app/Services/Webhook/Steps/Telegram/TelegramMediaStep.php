<?php

namespace App\Services\Webhook\Steps\Telegram;

use App\Services\TelegramService;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Downloads Telegram media and computes the placeholder content for non-text
 * messages. Bodies moved from ProcessTelegramWebhook::processMedia() and
 * ::generateMediaPlaceholder(); results are written to $ctx->metadata['media']
 * and $ctx->metadata['placeholder'] for PersistUserMessageStep.
 *
 * The recomputed placeholder here supersedes the pre-download value
 * TelegramEventMapper stored in metadata['placeholder'].
 */
class TelegramMediaStep
{
    public function __construct(private readonly TelegramService $telegramService) {}

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $mediaData = $this->processMedia($ctx);
        $ctx->metadata['media'] = $mediaData;
        $ctx->metadata['placeholder'] = $ctx->messageType() !== 'text'
            ? $this->generateMediaPlaceholder((string) $ctx->messageType(), $mediaData)
            : null;

        $next($ctx);
    }

    private function processMedia(WebhookContext $context): array
    {
        if ($context->messageType() === 'text') {
            return [];
        }

        $fileId = $context->metadata['file_id'] ?? null;

        if (! $fileId) {
            // For location, contact, poll - extract metadata only
            return [
                'metadata' => $context->metadata['media_metadata'] ?? [],
            ];
        }

        // Download and store the file
        $fileData = $this->telegramService->downloadAndStoreFile($context->bot, $fileId);

        if (! $fileData) {
            Log::warning('Failed to download Telegram media', [
                'bot_id' => $context->bot->id,
                'file_id' => $fileId,
                'type' => $context->messageType(),
            ]);

            return [
                'metadata' => array_merge(
                    $context->metadata['media_metadata'] ?? [],
                    ['file_id' => $fileId, 'download_failed' => true]
                ),
            ];
        }

        return [
            'url' => $fileData['url'],
            'mime_type' => $fileData['mime_type'],
            'metadata' => array_merge(
                $context->metadata['media_metadata'] ?? [],
                [
                    'file_id' => $fileId,
                    'file_size' => $fileData['file_size'],
                    'storage_path' => $fileData['path'],
                ]
            ),
        ];
    }

    private function generateMediaPlaceholder(string $type, array $mediaData): string
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
