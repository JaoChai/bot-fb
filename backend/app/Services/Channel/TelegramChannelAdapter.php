<?php

namespace App\Services\Channel;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\TelegramService;

/**
 * Telegram channel adapter for sending messages via Telegram Bot API.
 */
class TelegramChannelAdapter implements ChannelAdapterInterface
{
    public function __construct(
        private TelegramService $telegramService
    ) {}

    public function getChannelType(): string
    {
        return 'telegram';
    }

    public function sendMessage(
        Bot $bot,
        Conversation $conversation,
        string $type,
        string $content,
        ?string $mediaUrl = null
    ): void {
        $chatId = $conversation->external_customer_id;

        match ($type) {
            'photo', 'image' => $mediaUrl
                ? $this->telegramService->sendPhoto($bot, $chatId, $mediaUrl, $content ?: null)
                : $this->telegramService->sendMessage($bot, $chatId, $content),
            'video' => $mediaUrl
                ? $this->telegramService->sendVideo($bot, $chatId, $mediaUrl, $content ?: null)
                : $this->telegramService->sendMessage($bot, $chatId, $content),
            'document', 'file' => $mediaUrl
                ? $this->telegramService->sendDocument($bot, $chatId, $mediaUrl, $content ?: null)
                : $this->telegramService->sendMessage($bot, $chatId, $content),
            'voice' => $mediaUrl
                ? $this->telegramService->sendVoice($bot, $chatId, $mediaUrl)
                : $this->telegramService->sendMessage($bot, $chatId, $content),
            default => $this->telegramService->sendMessage($bot, $chatId, $content),
        };
    }

    public function supportsMedia(): bool
    {
        return true;
    }

    public function supportsHandover(): bool
    {
        // Telegram doesn't have handover mode - agent can always send
        return false;
    }

    public function getSupportedMessageTypes(): array
    {
        return ['text', 'image', 'photo', 'video', 'document', 'file', 'voice'];
    }
}
