<?php

namespace App\Services;

use App\Exceptions\FacebookException;
use App\Models\Bot;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FacebookService
{
    protected const API_VERSION = 'v18.0';
    protected const API_BASE_URL = 'https://graph.facebook.com';

    /**
     * Validate Facebook webhook signature.
     *
     * @throws FacebookException
     */
    public function validateSignature(string $payload, string $signature, string $appSecret): bool
    {
        if (empty($signature)) {
            throw new FacebookException('Missing X-Hub-Signature-256 header', 401);
        }

        // Signature format: sha256=xxx
        if (!str_starts_with($signature, 'sha256=')) {
            throw new FacebookException('Invalid signature format', 401);
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new FacebookException('Invalid Facebook webhook signature', 401);
        }

        return true;
    }

    /**
     * Parse Facebook webhook events from request body.
     */
    public function parseEvents(array $body): array
    {
        $events = [];

        // Facebook sends messaging events inside entry[].messaging[]
        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $messaging) {
                $events[] = array_merge($messaging, [
                    'page_id' => $entry['id'] ?? null,
                    'time' => $entry['time'] ?? null,
                ]);
            }
        }

        return $events;
    }

    /**
     * Extract sender PSID from a Facebook event.
     */
    public function extractSenderId(array $event): ?string
    {
        return $event['sender']['id'] ?? null;
    }

    /**
     * Extract recipient (page) ID from a Facebook event.
     */
    public function extractRecipientId(array $event): ?string
    {
        return $event['recipient']['id'] ?? null;
    }

    /**
     * Extract message content from a Facebook event.
     */
    public function extractMessage(array $event): array
    {
        $message = $event['message'] ?? [];

        return [
            'mid' => $message['mid'] ?? null,
            'text' => $message['text'] ?? null,
            'quick_reply_payload' => $message['quick_reply']['payload'] ?? null,
            'attachments' => $message['attachments'] ?? [],
            'is_echo' => $message['is_echo'] ?? false,
            'app_id' => $message['app_id'] ?? null,
            'metadata' => $message['metadata'] ?? null,
        ];
    }

    /**
     * Check if the event is a message event (not postback, delivery, read, etc).
     */
    public function isMessageEvent(array $event): bool
    {
        return isset($event['message']) && !($event['message']['is_echo'] ?? false);
    }

    /**
     * Check if the event is a text message.
     */
    public function isTextMessage(array $event): bool
    {
        return $this->isMessageEvent($event)
            && isset($event['message']['text'])
            && !isset($event['message']['attachments']);
    }

    /**
     * Check if the event is a postback (button click).
     */
    public function isPostbackEvent(array $event): bool
    {
        return isset($event['postback']);
    }

    /**
     * Check if the event is a quick reply.
     */
    public function isQuickReply(array $event): bool
    {
        return isset($event['message']['quick_reply']);
    }

    /**
     * Send a text message to a user.
     *
     * @throws FacebookException
     */
    public function sendMessage(Bot $bot, string $recipientId, string $text): array
    {
        $response = $this->client($bot)->post('/me/messages', [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => mb_substr($text, 0, 2000)], // Facebook limit
            'messaging_type' => 'RESPONSE',
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Unknown Facebook API error');
            $errorCode = $response->json('error.code', $response->status());

            Log::error('Facebook sendMessage failed', [
                'bot_id' => $bot->id,
                'recipient_id' => $recipientId,
                'error' => $error,
                'error_code' => $errorCode,
            ]);

            throw new FacebookException(
                "Failed to send message: {$error}",
                $errorCode,
                $response->json('error')
            );
        }

        return $response->json();
    }

    /**
     * Send a message with quick replies.
     *
     * @throws FacebookException
     */
    public function sendQuickReplies(Bot $bot, string $recipientId, string $text, array $quickReplies): array
    {
        $formattedReplies = array_map(fn($reply) => [
            'content_type' => 'text',
            'title' => mb_substr($reply['title'] ?? $reply, 0, 20), // Facebook limit
            'payload' => $reply['payload'] ?? $reply['title'] ?? $reply,
        ], array_slice($quickReplies, 0, 13)); // Facebook limit is 13 quick replies

        $response = $this->client($bot)->post('/me/messages', [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'text' => mb_substr($text, 0, 2000),
                'quick_replies' => $formattedReplies,
            ],
            'messaging_type' => 'RESPONSE',
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Unknown Facebook API error');
            throw new FacebookException("Failed to send quick replies: {$error}", $response->status());
        }

        return $response->json();
    }

    /**
     * Send an image attachment.
     *
     * @throws FacebookException
     */
    public function sendImage(Bot $bot, string $recipientId, string $imageUrl): array
    {
        return $this->sendAttachment($bot, $recipientId, 'image', $imageUrl);
    }

    /**
     * Send a video attachment.
     *
     * @throws FacebookException
     */
    public function sendVideo(Bot $bot, string $recipientId, string $videoUrl): array
    {
        return $this->sendAttachment($bot, $recipientId, 'video', $videoUrl);
    }

    /**
     * Send an audio attachment.
     *
     * @throws FacebookException
     */
    public function sendAudio(Bot $bot, string $recipientId, string $audioUrl): array
    {
        return $this->sendAttachment($bot, $recipientId, 'audio', $audioUrl);
    }

    /**
     * Send a file attachment.
     *
     * @throws FacebookException
     */
    public function sendFile(Bot $bot, string $recipientId, string $fileUrl): array
    {
        return $this->sendAttachment($bot, $recipientId, 'file', $fileUrl);
    }

    /**
     * Send an attachment (image, video, audio, file).
     *
     * @throws FacebookException
     */
    public function sendAttachment(Bot $bot, string $recipientId, string $type, string $url, bool $isReusable = false): array
    {
        $response = $this->client($bot)->post('/me/messages', [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => $type,
                    'payload' => [
                        'url' => $url,
                        'is_reusable' => $isReusable,
                    ],
                ],
            ],
            'messaging_type' => 'RESPONSE',
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Unknown Facebook API error');
            throw new FacebookException("Failed to send {$type}: {$error}", $response->status());
        }

        return $response->json();
    }

    /**
     * Send a generic template (cards/carousel).
     *
     * @throws FacebookException
     */
    public function sendGenericTemplate(Bot $bot, string $recipientId, array $elements): array
    {
        $response = $this->client($bot)->post('/me/messages', [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'generic',
                        'elements' => array_slice($elements, 0, 10), // Facebook limit
                    ],
                ],
            ],
            'messaging_type' => 'RESPONSE',
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Unknown Facebook API error');
            throw new FacebookException("Failed to send template: {$error}", $response->status());
        }

        return $response->json();
    }

    /**
     * Send a button template.
     *
     * @throws FacebookException
     */
    public function sendButtonTemplate(Bot $bot, string $recipientId, string $text, array $buttons): array
    {
        $response = $this->client($bot)->post('/me/messages', [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'button',
                        'text' => mb_substr($text, 0, 640), // Facebook limit
                        'buttons' => array_slice($buttons, 0, 3), // Facebook limit
                    ],
                ],
            ],
            'messaging_type' => 'RESPONSE',
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Unknown Facebook API error');
            throw new FacebookException("Failed to send button template: {$error}", $response->status());
        }

        return $response->json();
    }

    /**
     * Get user profile by PSID (Page-Scoped ID).
     */
    public function getProfile(Bot $bot, string $psid): array
    {
        $response = $this->client($bot)->get("/{$psid}", [
            'fields' => 'first_name,last_name,profile_pic',
        ]);

        if ($response->failed()) {
            Log::warning('Facebook getProfile failed', [
                'bot_id' => $bot->id,
                'psid' => $psid,
                'error' => $response->json('error.message'),
            ]);

            // Return empty profile instead of throwing - profile fetch is optional
            return [
                'id' => $psid,
                'first_name' => null,
                'last_name' => null,
                'profile_pic' => null,
            ];
        }

        return $response->json();
    }

    /**
     * Mark message as seen (sends read receipt).
     */
    public function markSeen(Bot $bot, string $recipientId): bool
    {
        $response = $this->client($bot)->post('/me/messages', [
            'recipient' => ['id' => $recipientId],
            'sender_action' => 'mark_seen',
        ]);

        return $response->successful();
    }

    /**
     * Show typing indicator (on).
     */
    public function typingOn(Bot $bot, string $recipientId): bool
    {
        $response = $this->client($bot)->post('/me/messages', [
            'recipient' => ['id' => $recipientId],
            'sender_action' => 'typing_on',
        ]);

        return $response->successful();
    }

    /**
     * Hide typing indicator (off).
     */
    public function typingOff(Bot $bot, string $recipientId): bool
    {
        $response = $this->client($bot)->post('/me/messages', [
            'recipient' => ['id' => $recipientId],
            'sender_action' => 'typing_off',
        ]);

        return $response->successful();
    }

    /**
     * Download attachment from Facebook CDN and store locally.
     * Facebook attachment URLs expire, so we need to download immediately.
     */
    public function downloadAndStoreFile(Bot $bot, string $url, string $type): ?array
    {
        try {
            $response = Http::timeout(60)->get($url);

            if ($response->failed()) {
                Log::error('Facebook file download failed', [
                    'bot_id' => $bot->id,
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            // Determine extension based on type
            $extension = match ($type) {
                'image' => 'jpg',
                'video' => 'mp4',
                'audio' => 'mp3',
                'file' => 'bin',
                default => 'bin',
            };

            // Check content-type header for better extension
            $contentType = $response->header('Content-Type');
            if ($contentType) {
                $extension = $this->getExtensionFromMimeType($contentType) ?? $extension;
            }

            // Generate storage path
            $storagePath = 'facebook/' . $bot->id . '/' . date('Y/m/d') . '/' . uniqid() . '.' . $extension;

            // Store file
            $disk = config('filesystems.default');
            Storage::disk($disk)->put($storagePath, $response->body());

            // Generate URL
            $storedUrl = $this->generateStorageUrl($disk, $storagePath);

            // Determine MIME type
            $mimeType = $contentType ?? match ($type) {
                'image' => 'image/jpeg',
                'video' => 'video/mp4',
                'audio' => 'audio/mpeg',
                default => 'application/octet-stream',
            };

            return [
                'url' => $storedUrl,
                'path' => $storagePath,
                'mime_type' => $mimeType,
            ];
        } catch (\Exception $e) {
            Log::error('Facebook file download exception', [
                'bot_id' => $bot->id,
                'url' => $url,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Set up the Messenger Profile (greeting, get started button, persistent menu).
     *
     * @throws FacebookException
     */
    public function setMessengerProfile(Bot $bot, array $profile): bool
    {
        $response = $this->client($bot)->post('/me/messenger_profile', $profile);

        if ($response->failed()) {
            $error = $response->json('error.message', 'Unknown error');
            throw new FacebookException("Failed to set messenger profile: {$error}", $response->status());
        }

        return true;
    }

    /**
     * Set greeting text shown before user starts conversation.
     */
    public function setGreeting(Bot $bot, string $text): bool
    {
        return $this->setMessengerProfile($bot, [
            'greeting' => [
                [
                    'locale' => 'default',
                    'text' => mb_substr($text, 0, 160), // Facebook limit
                ],
            ],
        ]);
    }

    /**
     * Set "Get Started" button payload.
     */
    public function setGetStartedButton(Bot $bot, string $payload): bool
    {
        return $this->setMessengerProfile($bot, [
            'get_started' => [
                'payload' => $payload,
            ],
        ]);
    }

    /**
     * Parse webhook event and extract message data.
     */
    public function parseEvent(array $event): array
    {
        $senderId = $this->extractSenderId($event);
        $message = $this->extractMessage($event);
        $postback = $event['postback'] ?? null;

        return [
            'sender_id' => $senderId,
            'recipient_id' => $this->extractRecipientId($event),
            'page_id' => $event['page_id'] ?? null,
            'timestamp' => $event['timestamp'] ?? null,
            'message_id' => $message['mid'],
            'text' => $message['text'] ?? $postback['title'] ?? null,
            'type' => $this->detectEventType($event),
            'quick_reply_payload' => $message['quick_reply_payload'],
            'postback_payload' => $postback['payload'] ?? null,
            'attachments' => $message['attachments'],
            'is_echo' => $message['is_echo'],
            'raw_event' => $event,
        ];
    }

    /**
     * Detect event type from Facebook event.
     */
    public function detectEventType(array $event): string
    {
        if (isset($event['delivery'])) {
            return 'delivery';
        }

        if (isset($event['read'])) {
            return 'read';
        }

        if (isset($event['postback'])) {
            return 'postback';
        }

        if (isset($event['referral'])) {
            return 'referral';
        }

        if (isset($event['message'])) {
            if ($event['message']['is_echo'] ?? false) {
                return 'echo';
            }

            $attachments = $event['message']['attachments'] ?? [];
            if (!empty($attachments)) {
                $type = $attachments[0]['type'] ?? 'unknown';
                return match ($type) {
                    'image' => 'image',
                    'video' => 'video',
                    'audio' => 'audio',
                    'file' => 'file',
                    'location' => 'location',
                    'fallback' => 'fallback', // Unsupported attachment
                    default => 'attachment',
                };
            }

            if (isset($event['message']['quick_reply'])) {
                return 'quick_reply';
            }

            return 'text';
        }

        return 'unknown';
    }

    /**
     * Extract attachment URLs from message.
     */
    public function extractAttachments(array $event): array
    {
        $attachments = [];
        $message = $event['message'] ?? [];

        foreach ($message['attachments'] ?? [] as $attachment) {
            $attachments[] = [
                'type' => $attachment['type'] ?? 'unknown',
                'url' => $attachment['payload']['url'] ?? null,
                'title' => $attachment['title'] ?? null,
                'sticker_id' => $attachment['payload']['sticker_id'] ?? null,
                'coordinates' => isset($attachment['payload']['coordinates']) ? [
                    'lat' => $attachment['payload']['coordinates']['lat'] ?? null,
                    'long' => $attachment['payload']['coordinates']['long'] ?? null,
                ] : null,
            ];
        }

        return $attachments;
    }

    /**
     * Get extension from MIME type.
     */
    protected function getExtensionFromMimeType(string $mimeType): ?string
    {
        // Extract base mime type (without parameters)
        $mimeType = explode(';', $mimeType)[0];

        return match (trim($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'application/pdf' => 'pdf',
            default => null,
        };
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
     * Get configured HTTP client for Facebook Graph API.
     */
    protected function client(Bot $bot): PendingRequest
    {
        $token = $bot->channel_access_token;

        if (empty($token)) {
            throw new FacebookException('Bot has no Facebook Page Access Token configured', 401);
        }

        return Http::baseUrl(self::API_BASE_URL . '/' . self::API_VERSION)
            ->withToken($token)
            ->timeout(30)
            ->acceptJson();
    }
}
