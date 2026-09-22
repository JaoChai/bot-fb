<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\LINEService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;

class AggregatedMessageDeliveryService
{
    public function __construct(
        private LINEService $line,
        private MultipleBubblesService $bubbles,
        private PaymentFlexService $paymentFlex,
    ) {}

    public function deliver(
        Bot $bot,
        Conversation $conversation,
        Message $botMessage,
        string $externalUserId,
    ): void {
        $transformed = $this->paymentFlex->tryConvertToFlex($botMessage->content, $conversation);

        if (is_array($transformed)) {
            $retryKey = $this->line->generateRetryKey();
            $this->line->push($bot, $externalUserId, [$transformed], $retryKey);

            return;
        }

        if ($this->bubbles->isEnabled($bot)) {
            $parsed = $this->bubbles->parseIntoBubbles($botMessage->content, $bot);
            $this->bubbles->sendBubbles($bot, $externalUserId, null, $parsed, $conversation);

            return;
        }

        $retryKey = $this->line->generateRetryKey();
        $this->line->push($bot, $externalUserId, [$botMessage->content], $retryKey);
    }
}
