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

        if (! hash_equals($expectedSignature, $signature)) {
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
     * Extract webhook event ID from a LINE event.
     * This is a unique identifier assigned by LINE to each webhook event.
     *
     * @see https://developers.line.biz/en/docs/messaging-api/receiving-messages/
     */
    public function extractWebhookEventId(array $event): ?string
    {
        return $event['webhookEventId'] ?? null;
    }

    /**
     * Check if this event is a redelivery.
     * LINE may redeliver events if the initial delivery failed or timed out.
     *
     * @see https://developers.line.biz/en/docs/messaging-api/receiving-messages/
     */
    public function isRedelivery(array $event): bool
    {
        return ($event['deliveryContext']['isRedelivery'] ?? false) === true;
    }

    /**
     * Extract event timestamp from a LINE event.
     * Returns milliseconds since epoch.
     */
    public function extractEventTimestamp(array $event): ?int
    {
        return isset($event['timestamp']) ? (int) $event['timestamp'] : null;
    }

    /**
     * Generate a retry key for idempotent API calls.
     * Uses UUIDv4 format as required by LINE API.
     *
     * @see https://developers.line.biz/en/docs/messaging-api/retrying-api-request/
     */
    public function generateRetryKey(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
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
     * @param  Bot  $bot  The bot instance
     * @param  string  $replyToken  The reply token from webhook event
     * @param  array  $messages  Messages to send (max 5)
     * @param  string|null  $retryKey  Optional X-Line-Retry-Key for idempotent retry
     *
     * @throws LINEException
     *
     * @see https://developers.line.biz/en/docs/messaging-api/retrying-api-request/
     */
    public function reply(Bot $bot, string $replyToken, array $messages, ?string $retryKey = null): bool
    {
        $headers = [];
        if ($retryKey) {
            $headers['X-Line-Retry-Key'] = $retryKey;
        }

        $response = $this->client($bot, $headers)->post('/bot/message/reply', [
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
                'retry_key' => $retryKey,
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
     * @param  Bot  $bot  The bot instance
     * @param  string  $userId  LINE user ID to send message to
     * @param  array  $messages  Messages to send (max 5)
     * @param  string|null  $retryKey  Optional X-Line-Retry-Key for idempotent retry
     *
     * @throws LINEException
     *
     * @see https://developers.line.biz/en/docs/messaging-api/retrying-api-request/
     */
    public function push(Bot $bot, string $userId, array $messages, ?string $retryKey = null): bool
    {
        $headers = [];
        if ($retryKey) {
            $headers['X-Line-Retry-Key'] = $retryKey;
        }

        $response = $this->client($bot, $headers)->post('/bot/message/push', [
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
                'retry_key' => $retryKey,
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
     * Reply with automatic fallback to push if reply token expired.
     *
     * LINE reply tokens expire very quickly (~10 seconds). This method attempts
     * to use the free reply() first, and automatically falls back to push()
     * if the token has expired.
     *
     * @param  Bot  $bot  The bot instance
     * @param  string|null  $replyToken  Reply token (null = use push directly)
     * @param  string  $userId  LINE user ID for fallback push
     * @param  array  $messages  Messages to send (max 5)
     * @param  string|null  $retryKey  Optional retry key for idempotency
     * @return array ['method' => 'reply'|'push', 'success' => bool]
     *
     * @throws LINEException If both reply and push fail
     *
     * @see https://developers.line.biz/en/docs/messaging-api/sending-messages/
     */
    public function replyWithFallback(
        Bot $bot,
        ?string $replyToken,
        string $userId,
        array $messages,
        ?string $retryKey = null
    ): array {
        // If no reply token, use push directly
        if (! $replyToken) {
            $pushRetryKey = $retryKey ?? $this->generateRetryKey();
            $this->push($bot, $userId, $messages, $pushRetryKey);

            Log::debug('LINE replyWithFallback: used push (no reply token)', [
                'bot_id' => $bot->id,
                'user_id' => $userId,
            ]);

            return ['method' => 'push', 'success' => true];
        }

        try {
            // Try reply first (free, doesn't count towards quota)
            $this->reply($bot, $replyToken, $messages, $retryKey);

            return ['method' => 'reply', 'success' => true];

        } catch (LINEException $e) {
            // Check if the error is due to expired reply token
            if ($e->isReplyTokenExpired()) {
                Log::info('LINE reply token expired, falling back to push', [
                    'bot_id' => $bot->id,
                    'user_id' => $userId,
                    'original_error' => $e->getMessage(),
                ]);

                // Fallback to push (counts towards quota but ensures delivery)
                $pushRetryKey = $this->generateRetryKey();
                $this->push($bot, $userId, $messages, $pushRetryKey);

                return ['method' => 'push', 'success' => true];
            }

            // For other errors, re-throw
            throw $e;
        }
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
     * @param  string  $userId  LINE User ID (chatId)
     * @param  int  $seconds  Duration 5-60 seconds (default: 20)
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
            'Authorization' => 'Bearer '.$bot->channel_access_token,
        ])->timeout(30)->get(self::DATA_API_BASE_URL."/bot/message/{$messageId}/content");

        if ($response->failed()) {
            throw new LINEException(
                'Failed to get message content',
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
            $storagePath = 'line/'.$bot->id.'/'.date('Y/m/d').'/'.uniqid().'.'.$extension;

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
                return rtrim($r2Url, '/').'/'.$path;
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
     *
     * @param  Bot  $bot  The bot instance with channel access token
     * @param  array  $additionalHeaders  Optional headers (e.g., X-Line-Retry-Key)
     */
    protected function client(Bot $bot, array $additionalHeaders = []): PendingRequest
    {
        if (empty($bot->channel_access_token)) {
            throw new LINEException('Bot has no LINE channel access token configured', 401);
        }

        $headers = array_merge([
            'Authorization' => 'Bearer '.$bot->channel_access_token,
        ], $additionalHeaders);

        return Http::baseUrl(self::API_BASE_URL)
            ->withHeaders($headers)
            ->timeout(30)
            ->acceptJson();
    }
}
