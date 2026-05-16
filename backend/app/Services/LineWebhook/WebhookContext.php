<?php

namespace App\Services\LineWebhook;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;

class WebhookContext
{
    public ?CustomerProfile $profile = null;

    public ?Conversation $conversation = null;

    public ?Message $userMessage = null;

    public ?ResponseEnvelope $response = null;

    public ?GateDecision $gateDecision = null;

    /** @var array<string,mixed> */
    public array $metadata = [];

    public bool $aggregationBuffered = false;

    public function __construct(
        public readonly Bot $bot,
        public readonly array $event,
    ) {}

    public function messageType(): ?string
    {
        return $this->event['message']['type'] ?? null;
    }

    public function userId(): ?string
    {
        return $this->event['source']['userId'] ?? null;
    }

    public function replyToken(): ?string
    {
        return $this->event['replyToken'] ?? null;
    }
}
