<?php

namespace App\Services\Channel;

use InvalidArgumentException;

/**
 * Factory for creating channel adapters.
 *
 * Resolves the appropriate adapter based on channel type.
 */
class ChannelAdapterFactory
{
    /**
     * @var array<string, ChannelAdapterInterface>
     */
    private array $adapters = [];

    public function __construct(
        LINEChannelAdapter $lineAdapter,
        TelegramChannelAdapter $telegramAdapter
    ) {
        $this->adapters['line'] = $lineAdapter;
        $this->adapters['telegram'] = $telegramAdapter;
    }

    /**
     * Get the adapter for a specific channel type.
     *
     * @throws InvalidArgumentException If channel type is not supported
     */
    public function make(string $channelType): ChannelAdapterInterface
    {
        if (! isset($this->adapters[$channelType])) {
            throw new InvalidArgumentException(
                "Unsupported channel type: {$channelType}. Supported: ".implode(', ', $this->getSupportedChannels())
            );
        }

        return $this->adapters[$channelType];
    }

    /**
     * Check if a channel type is supported.
     */
    public function supports(string $channelType): bool
    {
        return isset($this->adapters[$channelType]);
    }

    /**
     * Get list of supported channel types.
     *
     * @return array<string>
     */
    public function getSupportedChannels(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Register a new adapter.
     *
     * Useful for adding new channels without modifying the factory.
     */
    public function register(string $channelType, ChannelAdapterInterface $adapter): void
    {
        $this->adapters[$channelType] = $adapter;
    }
}
