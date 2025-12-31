<?php

namespace App\Services;

use App\Exceptions\LINEException;
use App\Models\Bot;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LINEService
{
    protected const API_BASE_URL = 'https://api.line.me/v2';
    protected const DATA_API_BASE_URL = 'https://api-data.line.me/v2';

    /**
     * Validate LINE webhook signature.
     *
     * @throws LINEException
     */
    public function validateSignature(string $body, string $signature, string $channelSecret): bool
    {
        $hash = hash_hmac('sha256', $body, $channelSecret, true);
        $expectedSignature = base64_encode($hash);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new LINEException('Invalid LINE webhook signature', 401);
        }

        return true;
    }

    /**
     * Parse LINE webhook events from request body.
     */
    public function parseEvents(array $body): array
    {
        return $body['events'] ?? [];
    }

    /**
     * Extract user ID from a LINE event.
     */
    public function extractUserId(array $event): ?string
    {
        return $event['source']['userId'] ?? null;
    }

    /**
     * Extract reply token from a LINE event.
     */
    public function extractReplyToken(array $event): ?string
    {
        return $event['replyToken'] ?? null;
    }

    /**
     * Extract message content from a LINE event.
     */
    public function extractMessage(array $event): array
    {
        $message = $event['message'] ?? [];

        return [
            'id' => $message['id'] ?? null,
            'type' => $message['type'] ?? 'unknown',
            'text' => $message['text'] ?? null,
            'content_provider' => $message['contentProvider'] ?? null,
            'sticker_id' => $message['stickerId'] ?? null,
            'package_id' => $message['packageId'] ?? null,
            'latitude' => $message['latitude'] ?? null,
            'longitude' => $message['longitude'] ?? null,
            'address' => $message['address'] ?? null,
            'duration' => $message['duration'] ?? null,
        ];
    }

    /**
     * Check if the event is a message event.
     */
    public function isMessageEvent(array $event): bool
    {
        return ($event['type'] ?? '') === 'message';
    }

    /**
     * Check if the event is a text message.
     */
    public function isTextMessage(array $event): bool
    {
        return $this->isMessageEvent($event)
            && ($event['message']['type'] ?? '') === 'text';
    }

    /**
     * Reply to a message using the reply token.
     *
     * @throws LINEException
     */
    public function reply(Bot $bot, string $replyToken, array $messages): bool
    {
        $response = $this->client($bot)->post('/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages' => $this->formatMessages($messages),
        ]);

        if ($response->failed()) {
            $error = $response->json('message', 'Unknown LINE API error');
            Log::error('LINE reply failed', [
                'bot_id' => $bot->id,
                'status' => $response->status(),
                'error' => $error,
                'details' => $response->json('details'),
            ]);

            throw new LINEException(
                "Failed to send LINE reply: {$error}",
                $response->status(),
                $response->json('details')
            );
        }

        return true;
    }

    /**
     * Send a push message to a user.
     *
     * @throws LINEException
     */
    public function push(Bot $bot, string $userId, array $messages): bool
    {
        $response = $this->client($bot)->post('/bot/message/push', [
            'to' => $userId,
            'messages' => $this->formatMessages($messages),
        ]);

        if ($response->failed()) {
            $error = $response->json('message', 'Unknown LINE API error');
            Log::error('LINE push failed', [
                'bot_id' => $bot->id,
                'user_id' => $userId,
                'status' => $response->status(),
                'error' => $error,
            ]);

            throw new LINEException(
                "Failed to send LINE push: {$error}",
                $response->status(),
                $response->json('details')
            );
        }

        return true;
    }

    /**
     * Get user profile from LINE.
     *
     * @throws LINEException
     */
    public function getProfile(Bot $bot, string $userId): array
    {
        $response = $this->client($bot)->get("/bot/profile/{$userId}");

        if ($response->failed()) {
            Log::warning('Failed to get LINE user profile', [
                'bot_id' => $bot->id,
                'user_id' => $userId,
                'status' => $response->status(),
            ]);

            // Return empty profile instead of throwing - profile fetch is optional
            return [
                'userId' => $userId,
                'displayName' => null,
                'pictureUrl' => null,
                'statusMessage' => null,
            ];
        }

        return $response->json();
    }

    /**
     * Display loading indicator to user.
     * Shows typing animation while bot is processing.
     *
     * @param Bot $bot
     * @param string $userId LINE User ID (chatId)
     * @param int $seconds Duration 5-60 seconds (default: 20)
     * @return bool
     */
    public function showLoadingIndicator(Bot $bot, string $userId, int $seconds = 20): bool
    {
        // Clamp seconds to valid range (LINE API constraint: 5-60)
        $seconds = max(5, min(60, $seconds));

        try {
            $response = $this->client($bot)->post('/bot/chat/loading/start', [
                'chatId' => $userId,
                'loadingSeconds' => $seconds,
            ]);

            if ($response->failed()) {
                Log::warning('Failed to show LINE loading indicator', [
                    'bot_id' => $bot->id,
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'error' => $response->json('message'),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // Non-critical - don't let loading indicator failure break the flow
            Log::warning('LINE loading indicator exception', [
                'bot_id' => $bot->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get message content (for images, videos, audio, files).
     *
     * @throws LINEException
     */
    public function getContent(Bot $bot, string $messageId): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bot->channel_access_token,
        ])->timeout(30)->get(self::DATA_API_BASE_URL . "/bot/message/{$messageId}/content");

        if ($response->failed()) {
            throw new LINEException(
                "Failed to get message content",
                $response->status()
            );
        }

        return $response->body();
    }

    /**
     * Download and store message content (images, videos, audio, files).
     * Returns array with url, path, mime_type or null on failure.
     */
    public function downloadAndStoreFile(Bot $bot, string $messageId, string $type): ?array
    {
        try {
            $content = $this->getContent($bot, $messageId);

            // Determine extension based on type
            $extension = match ($type) {
                'image' => 'jpg',
                'video' => 'mp4',
                'audio' => 'm4a',
                'file' => 'bin',
                default => 'bin',
            };

            // Generate storage path
            $storagePath = 'line/' . $bot->id . '/' . date('Y/m/d') . '/' . uniqid() . '.' . $extension;

            // Store file
            $disk = config('filesystems.default');
            Storage::disk($disk)->put($storagePath, $content);

            // Generate URL
            $url = $this->generateStorageUrl($disk, $storagePath);

            // Determine MIME type
            $mimeType = match ($type) {
                'image' => 'image/jpeg',
                'video' => 'video/mp4',
                'audio' => 'audio/m4a',
                default => 'application/octet-stream',
            };

            return [
                'url' => $url,
                'path' => $storagePath,
                'mime_type' => $mimeType,
            ];
        } catch (\Exception $e) {
            Log::error('LINE file download failed', [
                'bot_id' => $bot->id,
                'message_id' => $messageId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate storage URL - use R2_URL directly if R2 disk.
     */
    protected function generateStorageUrl(string $disk, string $path): string
    {
        if ($disk === 'r2') {
            $r2Url = env('R2_URL') ?: config('filesystems.disks.r2.url');
            if ($r2Url) {
                return rtrim($r2Url, '/') . '/' . $path;
            }
        }

        return Storage::disk($disk)->url($path);
    }

    /**
     * Create a simple text message.
     */
    public function textMessage(string $text): array
    {
        return [
            'type' => 'text',
            'text' => mb_substr($text, 0, 5000), // LINE limit
        ];
    }

    /**
     * Create a quick reply message.
     */
    public function quickReplyMessage(string $text, array $items): array
    {
        return [
            'type' => 'text',
            'text' => $text,
            'quickReply' => [
                'items' => array_map(fn ($item) => [
                    'type' => 'action',
                    'action' => [
                        'type' => 'message',
                        'label' => mb_substr($item['label'], 0, 20),
                        'text' => $item['text'] ?? $item['label'],
                    ],
                ], array_slice($items, 0, 13)), // LINE limit is 13 items
            ],
        ];
    }

    /**
     * Create an image message.
     * LINE requires HTTPS URLs. Max image size: 10MB.
     */
    public function imageMessage(string $originalUrl, ?string $previewUrl = null): array
    {
        return [
            'type' => 'image',
            'originalContentUrl' => $originalUrl,
            'previewImageUrl' => $previewUrl ?? $originalUrl,
        ];
    }

    /**
     * Create a video message.
     * LINE requires HTTPS URLs and a preview image. Max video size: 200MB.
     */
    public function videoMessage(string $originalUrl, ?string $previewUrl = null): array
    {
        // Use a default video thumbnail if none provided
        $defaultPreview = 'https://cdn.botjao.com/defaults/video-thumbnail.png';

        return [
            'type' => 'video',
            'originalContentUrl' => $originalUrl,
            'previewImageUrl' => $previewUrl ?? $defaultPreview,
        ];
    }

    /**
     * Create an audio message.
     * LINE requires HTTPS URLs. Duration in milliseconds (max 1 minute = 60000ms).
     */
    public function audioMessage(string $originalUrl, int $durationMs = 60000): array
    {
        return [
            'type' => 'audio',
            'originalContentUrl' => $originalUrl,
            'duration' => min($durationMs, 60000),
        ];
    }

    /**
     * Format messages for LINE API.
     */
    protected function formatMessages(array $messages): array
    {
        return array_slice(array_map(function ($message) {
            if (is_string($message)) {
                return $this->textMessage($message);
            }
            return $message;
        }, $messages), 0, 5); // LINE limit is 5 messages per request
    }

    /**
     * Get configured HTTP client for LINE API.
     */
    protected function client(Bot $bot): PendingRequest
    {
        if (empty($bot->channel_access_token)) {
            throw new LINEException('Bot has no LINE channel access token configured', 401);
        }

        return Http::baseUrl(self::API_BASE_URL)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $bot->channel_access_token,
            ])
            ->timeout(30)
            ->acceptJson();
    }
}
