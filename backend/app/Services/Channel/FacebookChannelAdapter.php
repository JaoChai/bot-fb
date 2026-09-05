<?php

namespace App\Services\Channel;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\FacebookService;

class FacebookChannelAdapter implements ChannelAdapterInterface
{
    public function __construct(
        private FacebookService $facebookService
    ) {}

    public function getChannelType(): string
    {
        return 'facebook';
    }

    public function sendMessage(
        Bot $bot,
        Conversation $conversation,
        string $type,
        string $content,
        ?string $mediaUrl = null
    ): void {
        $psid = $conversation->external_customer_id;

        match ($type) {
            'photo', 'image' => $mediaUrl
                ? $this->facebookService->sendImage($bot, $psid, $mediaUrl)
                : $this->facebookService->sendMessage($bot, $psid, $content),
            'video' => $mediaUrl
                ? $this->facebookService->sendVideo($bot, $psid, $mediaUrl)
                : $this->facebookService->sendMessage($bot, $psid, $content),
            'audio', 'voice' => $mediaUrl
                ? $this->facebookService->sendAudio($bot, $psid, $mediaUrl)
                : $this->facebookService->sendMessage($bot, $psid, $content),
            'file' => $mediaUrl
                ? $this->facebookService->sendFile($bot, $psid, $mediaUrl)
                : $this->facebookService->sendMessage($bot, $psid, $content),
            default => $this->facebookService->sendMessage($bot, $psid, $content),
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
        return ['text', 'image', 'photo', 'video', 'audio', 'voice', 'file'];
    }
}
