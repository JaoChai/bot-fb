<?php

namespace App\Services;

use App\Exceptions\TelegramException;
use App\Models\Bot;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramService
{
    protected const API_BASE_URL = 'https://api.telegram.org/bot';
    protected const FILE_BASE_URL = 'https://api.telegram.org/file/bot';

    /**
     * Set webhook URL for the bot.
     */
    public function setWebhook(Bot $bot, string $url, ?string $secretToken = null): bool
    {
        $params = ['url' => $url];

        if ($secretToken) {
            $params['secret_token'] = $secretToken;
        }

        $response = $this->client($bot)->post('/setWebhook', $params);

        if ($response->failed() || !$response->json('ok')) {
            Log::error('Telegram setWebhook failed', [
                'bot_id' => $bot->id,
                'url' => $url,
                'error' => $response->json('description'),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Delete webhook for the bot.
     */
    public function deleteWebhook(Bot $bot): bool
    {
        $response = $this->client($bot)->post('/deleteWebhook');

        return $response->successful() && $response->json('ok');
    }

    /**
     * Get webhook info.
     */
    public function getWebhookInfo(Bot $bot): array
    {
        $response = $this->client($bot)->get('/getWebhookInfo');

        if ($response->failed()) {
            return [];
        }

        return $response->json('result', []);
    }

    /**
     * Get bot info (validate token).
     */
    public function getMe(Bot $bot): array
    {
        $response = $this->client($bot)->get('/getMe');

        if ($response->failed() || !$response->json('ok')) {
            throw new TelegramException(
                'Invalid Telegram bot token: ' . $response->json('description', 'Unknown error'),
                401
            );
        }

        return $response->json('result', []);
    }

    /**
     * Get chat info.
     */
    public function getChat(Bot $bot, string $chatId): array
    {
        $response = $this->client($bot)->post('/getChat', [
            'chat_id' => $chatId,
        ]);

        if ($response->failed()) {
            Log::warning('Telegram getChat failed', [
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
                'error' => $response->json('description'),
            ]);
            return [];
        }

        return $response->json('result', []);
    }

    /**
     * Get file info by file_id.
     */
    public function getFile(Bot $bot, string $fileId): array
    {
        $response = $this->client($bot)->post('/getFile', [
            'file_id' => $fileId,
        ]);

        if ($response->failed() || !$response->json('ok')) {
            throw new TelegramException(
                'Failed to get file: ' . $response->json('description', 'Unknown error'),
                $response->status()
            );
        }

        return $response->json('result', []);
    }

    /**
     * Get file download URL.
     */
    public function getFileUrl(Bot $bot, string $filePath): string
    {
        return self::FILE_BASE_URL . $bot->channel_access_token . '/' . $filePath;
    }

    /**
     * Download file and store locally, return public URL.
     */
    public function downloadAndStoreFile(Bot $bot, string $fileId): ?array
    {
        try {
            $fileInfo = $this->getFile($bot, $fileId);
            $filePath = $fileInfo['file_path'] ?? null;

            if (!$filePath) {
                return null;
            }

            $fileUrl = $this->getFileUrl($bot, $filePath);
            $fileContent = Http::timeout(60)->get($fileUrl)->body();

            // Generate storage path
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $storagePath = 'telegram/' . $bot->id . '/' . date('Y/m/d') . '/' . uniqid() . '.' . $extension;

            // Store file
            Storage::disk('public')->put($storagePath, $fileContent);

            return [
                'url' => Storage::disk('public')->url($storagePath),
                'path' => $storagePath,
                'file_size' => $fileInfo['file_size'] ?? null,
                'mime_type' => $this->guessMimeType($extension),
            ];
        } catch (\Exception $e) {
            Log::error('Telegram file download failed', [
                'bot_id' => $bot->id,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send text message.
     */
    public function sendMessage(Bot $bot, string $chatId, string $text, array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4096), // Telegram limit
            'parse_mode' => 'HTML',
        ], $options);

        $response = $this->client($bot)->post('/sendMessage', $params);

        if ($response->failed() || !$response->json('ok')) {
            $error = $response->json('description', 'Unknown Telegram API error');
            Log::error('Telegram sendMessage failed', [
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
                'error' => $error,
            ]);

            throw new TelegramException("Failed to send message: {$error}", $response->status());
        }

        return $response->json('result', []);
    }

    /**
     * Send photo.
     */
    public function sendPhoto(Bot $bot, string $chatId, string $photo, ?string $caption = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo,
        ];

        if ($caption) {
            $params['caption'] = mb_substr($caption, 0, 1024);
            $params['parse_mode'] = 'HTML';
        }

        $response = $this->client($bot)->post('/sendPhoto', $params);

        if ($response->failed() || !$response->json('ok')) {
            throw new TelegramException(
                'Failed to send photo: ' . $response->json('description', 'Unknown error'),
                $response->status()
            );
        }

        return $response->json('result', []);
    }

    /**
     * Send video.
     */
    public function sendVideo(Bot $bot, string $chatId, string $video, ?string $caption = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'video' => $video,
        ];

        if ($caption) {
            $params['caption'] = mb_substr($caption, 0, 1024);
            $params['parse_mode'] = 'HTML';
        }

        $response = $this->client($bot)->post('/sendVideo', $params);

        if ($response->failed() || !$response->json('ok')) {
            throw new TelegramException(
                'Failed to send video: ' . $response->json('description', 'Unknown error'),
                $response->status()
            );
        }

        return $response->json('result', []);
    }

    /**
     * Send document.
     */
    public function sendDocument(Bot $bot, string $chatId, string $document, ?string $caption = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'document' => $document,
        ];

        if ($caption) {
            $params['caption'] = mb_substr($caption, 0, 1024);
            $params['parse_mode'] = 'HTML';
        }

        $response = $this->client($bot)->post('/sendDocument', $params);

        if ($response->failed() || !$response->json('ok')) {
            throw new TelegramException(
                'Failed to send document: ' . $response->json('description', 'Unknown error'),
                $response->status()
            );
        }

        return $response->json('result', []);
    }

    /**
     * Send voice message.
     */
    public function sendVoice(Bot $bot, string $chatId, string $voice, ?string $caption = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'voice' => $voice,
        ];

        if ($caption) {
            $params['caption'] = mb_substr($caption, 0, 1024);
        }

        $response = $this->client($bot)->post('/sendVoice', $params);

        if ($response->failed() || !$response->json('ok')) {
            throw new TelegramException(
                'Failed to send voice: ' . $response->json('description', 'Unknown error'),
                $response->status()
            );
        }

        return $response->json('result', []);
    }

    /**
     * Send chat action (typing, upload_photo, etc).
     */
    public function sendChatAction(Bot $bot, string $chatId, string $action = 'typing'): bool
    {
        $response = $this->client($bot)->post('/sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ]);

        return $response->successful() && $response->json('ok');
    }

    /**
     * Parse Telegram update and extract message data.
     */
    public function parseUpdate(array $update): array
    {
        $message = $update['message'] ?? $update['edited_message'] ?? $update['channel_post'] ?? null;

        if (!$message) {
            return [
                'type' => 'unknown',
                'update_id' => $update['update_id'] ?? null,
            ];
        }

        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];

        return [
            'update_id' => $update['update_id'] ?? null,
            'message_id' => $message['message_id'] ?? null,
            'chat_id' => (string) ($chat['id'] ?? ''),
            'chat_type' => $chat['type'] ?? 'private', // private, group, supergroup, channel
            'chat_title' => $chat['title'] ?? null,
            'user_id' => (string) ($from['id'] ?? ''),
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
            'text' => $message['text'] ?? $message['caption'] ?? null,
            'type' => $this->detectMessageType($message),
            'date' => $message['date'] ?? null,
            'is_edited' => isset($update['edited_message']),
            'reply_to_message_id' => $message['reply_to_message']['message_id'] ?? null,
            'raw_message' => $message,
        ];
    }

    /**
     * Detect message type from Telegram message object.
     */
    public function detectMessageType(array $message): string
    {
        return match (true) {
            isset($message['photo']) => 'photo',
            isset($message['video']) => 'video',
            isset($message['video_note']) => 'video',
            isset($message['voice']) => 'voice',
            isset($message['audio']) => 'audio',
            isset($message['document']) => 'file',
            isset($message['sticker']) => 'sticker',
            isset($message['location']) => 'location',
            isset($message['venue']) => 'location',
            isset($message['contact']) => 'contact',
            isset($message['poll']) => 'poll',
            isset($message['animation']) => 'animation',
            isset($message['text']) => 'text',
            default => 'unknown',
        };
    }

    /**
     * Extract file_id from message based on type.
     */
    public function extractFileId(array $message): ?string
    {
        // Photo array - get largest size
        if (isset($message['photo']) && is_array($message['photo'])) {
            $largest = end($message['photo']);
            return $largest['file_id'] ?? null;
        }

        // Video
        if (isset($message['video'])) {
            return $message['video']['file_id'] ?? null;
        }

        // Video note (round video)
        if (isset($message['video_note'])) {
            return $message['video_note']['file_id'] ?? null;
        }

        // Voice
        if (isset($message['voice'])) {
            return $message['voice']['file_id'] ?? null;
        }

        // Audio
        if (isset($message['audio'])) {
            return $message['audio']['file_id'] ?? null;
        }

        // Document
        if (isset($message['document'])) {
            return $message['document']['file_id'] ?? null;
        }

        // Sticker
        if (isset($message['sticker'])) {
            return $message['sticker']['file_id'] ?? null;
        }

        // Animation (GIF)
        if (isset($message['animation'])) {
            return $message['animation']['file_id'] ?? null;
        }

        return null;
    }

    /**
     * Extract media metadata from message.
     */
    public function extractMediaMetadata(array $message): array
    {
        $metadata = [];
        $type = $this->detectMessageType($message);

        switch ($type) {
            case 'photo':
                $photo = end($message['photo']);
                $metadata = [
                    'width' => $photo['width'] ?? null,
                    'height' => $photo['height'] ?? null,
                    'file_size' => $photo['file_size'] ?? null,
                ];
                break;

            case 'video':
                $video = $message['video'] ?? $message['video_note'] ?? [];
                $metadata = [
                    'width' => $video['width'] ?? null,
                    'height' => $video['height'] ?? null,
                    'duration' => $video['duration'] ?? null,
                    'file_size' => $video['file_size'] ?? null,
                    'file_name' => $video['file_name'] ?? null,
                    'mime_type' => $video['mime_type'] ?? null,
                ];
                break;

            case 'voice':
            case 'audio':
                $audio = $message['voice'] ?? $message['audio'] ?? [];
                $metadata = [
                    'duration' => $audio['duration'] ?? null,
                    'file_size' => $audio['file_size'] ?? null,
                    'mime_type' => $audio['mime_type'] ?? null,
                    'title' => $audio['title'] ?? null,
                    'performer' => $audio['performer'] ?? null,
                ];
                break;

            case 'file':
                $doc = $message['document'] ?? [];
                $metadata = [
                    'file_name' => $doc['file_name'] ?? null,
                    'file_size' => $doc['file_size'] ?? null,
                    'mime_type' => $doc['mime_type'] ?? null,
                ];
                break;

            case 'sticker':
                $sticker = $message['sticker'] ?? [];
                $metadata = [
                    'width' => $sticker['width'] ?? null,
                    'height' => $sticker['height'] ?? null,
                    'emoji' => $sticker['emoji'] ?? null,
                    'set_name' => $sticker['set_name'] ?? null,
                    'is_animated' => $sticker['is_animated'] ?? false,
                    'is_video' => $sticker['is_video'] ?? false,
                ];
                break;

            case 'location':
                $location = $message['location'] ?? $message['venue']['location'] ?? [];
                $metadata = [
                    'latitude' => $location['latitude'] ?? null,
                    'longitude' => $location['longitude'] ?? null,
                ];
                if (isset($message['venue'])) {
                    $metadata['title'] = $message['venue']['title'] ?? null;
                    $metadata['address'] = $message['venue']['address'] ?? null;
                }
                break;

            case 'contact':
                $contact = $message['contact'] ?? [];
                $metadata = [
                    'phone' => $contact['phone_number'] ?? null,
                    'first_name' => $contact['first_name'] ?? null,
                    'last_name' => $contact['last_name'] ?? null,
                    'user_id' => $contact['user_id'] ?? null,
                    'vcard' => $contact['vcard'] ?? null,
                ];
                break;

            case 'poll':
                $poll = $message['poll'] ?? [];
                $metadata = [
                    'question' => $poll['question'] ?? null,
                    'options' => array_map(fn($o) => $o['text'] ?? '', $poll['options'] ?? []),
                    'total_voter_count' => $poll['total_voter_count'] ?? 0,
                    'is_closed' => $poll['is_closed'] ?? false,
                    'is_anonymous' => $poll['is_anonymous'] ?? true,
                    'type' => $poll['type'] ?? 'regular',
                ];
                break;

            case 'animation':
                $anim = $message['animation'] ?? [];
                $metadata = [
                    'width' => $anim['width'] ?? null,
                    'height' => $anim['height'] ?? null,
                    'duration' => $anim['duration'] ?? null,
                    'file_size' => $anim['file_size'] ?? null,
                    'file_name' => $anim['file_name'] ?? null,
                    'mime_type' => $anim['mime_type'] ?? null,
                ];
                break;
        }

        return $metadata;
    }

    /**
     * Guess MIME type from file extension.
     */
    protected function guessMimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg', 'oga' => 'audio/ogg',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }

    /**
     * Get configured HTTP client for Telegram API.
     */
    protected function client(Bot $bot): PendingRequest
    {
        $token = $bot->channel_access_token;

        if (empty($token)) {
            throw new TelegramException('Bot has no Telegram token configured', 401);
        }

        return Http::baseUrl(self::API_BASE_URL . $token)
            ->timeout(30)
            ->acceptJson();
    }
}
