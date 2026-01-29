<?php

namespace App\Services\Channel;

use App\Models\Bot;
use App\Models\Conversation;

/**
 * Interface for channel-specific messaging adapters.
 *
 * Each channel (LINE, Telegram, Facebook) implements this interface
 * to provide consistent message sending capabilities.
 */
interface ChannelAdapterInterface
{
    /**
     * Get the channel type identifier.
     */
    public function getChannelType(): string;

    /**
     * Send a message to a customer via this channel.
     *
     * @param Bot $bot The bot sending the message
     * @param Conversation $conversation The conversation context
     * @param string $type Message type (text, image, video, audio, file)
     * @param string $content Text content of the message
     * @param string|null $mediaUrl Optional media URL for non-text messages
     */
    public function sendMessage(
        Bot $bot,
        Conversation $conversation,
        string $type,
        string $content,
        ?string $mediaUrl = null
    ): void;

    /**
     * Check if this adapter supports media messages.
     */
    public function supportsMedia(): bool;

    /**
     * Check if this adapter supports handover mode.
     */
    public function supportsHandover(): bool;

    /**
     * Get supported message types for this channel.
     *
     * @return array<string>
     */
    public function getSupportedMessageTypes(): array;
}
