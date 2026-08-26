<?php

namespace App\Services\Webhook;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;

class WebhookContext
{
    public ?CustomerProfile $profile = null;

    public ?Conversation $conversation = null;

    public ?Message $userMessage = null;

    /** @var array<string,mixed> */
    public array $metadata = [];

    public bool $aggregationBuffered = false;

    public function __construct(
        public readonly Bot $bot,
        public readonly array $rawEvent,
        public readonly string $channelType,
    ) {}

    public function eventType(): ?string
    {
        return $this->rawEvent['type'] ?? null;
    }

    public function text(): ?string
    {
        return $this->rawEvent['message']['text'] ?? $this->rawEvent['text'] ?? null;
    }

    public function messageType(): ?string
    {
        return $this->rawEvent['message']['type'] ?? null;
    }

    public function userId(): ?string
    {
        return $this->rawEvent['source']['userId'] ?? null;
    }

    public function replyToken(): ?string
    {
        return $this->rawEvent['replyToken'] ?? null;
    }
}
