<?php

namespace App\Services\Channel;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\LINEService;

/**
 * LINE channel adapter for sending messages via LINE Messaging API.
 */
class LINEChannelAdapter implements ChannelAdapterInterface
{
    public function __construct(
        private LINEService $lineService
    ) {}

    public function getChannelType(): string
    {
        return 'line';
    }

    public function sendMessage(
        Bot $bot,
        Conversation $conversation,
        string $type,
        string $content,
        ?string $mediaUrl = null
    ): void {
        $userId = $conversation->external_customer_id;

        // Generate retry key for idempotency (LINE best practice)
        $retryKey = $this->lineService->generateRetryKey();

        match ($type) {
            'photo', 'image' => $mediaUrl
                ? $this->lineService->push($bot, $userId, [$this->lineService->imageMessage($mediaUrl)], $retryKey)
                : $this->lineService->push($bot, $userId, [$this->lineService->textMessage($content)], $retryKey),
            'video' => $mediaUrl
                ? $this->lineService->push($bot, $userId, [$this->lineService->videoMessage($mediaUrl)], $retryKey)
                : $this->lineService->push($bot, $userId, [$this->lineService->textMessage($content)], $retryKey),
            'audio', 'voice' => $mediaUrl
                ? $this->lineService->push($bot, $userId, [$this->lineService->audioMessage($mediaUrl)], $retryKey)
                : $this->lineService->push($bot, $userId, [$this->lineService->textMessage($content)], $retryKey),
            default => $this->lineService->push($bot, $userId, [$this->lineService->textMessage($content)], $retryKey),
        };
    }

    public function supportsMedia(): bool
    {
        return true;
    }

    public function supportsHandover(): bool
    {
        return true;
    }

    public function getSupportedMessageTypes(): array
    {
        return ['text', 'image', 'photo', 'video', 'audio', 'voice'];
    }
}
