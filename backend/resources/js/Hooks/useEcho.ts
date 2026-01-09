import { useEffect, useRef, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { getEcho } from '@/Lib/echo';
import type Echo from 'laravel-echo';

type Channel = ReturnType<Echo<'reverb'>['private']>;

interface UseEchoOptions {
  /** Channel name (without 'private-' prefix) */
  channel: string;
  /** Event name to listen for */
  event: string;
  /** Callback when event is received */
  onEvent?: (data: unknown) => void;
  /** Inertia props to reload (default: all) */
  reloadOnly?: string[];
  /** Whether to auto-reload on event (default: true) */
  autoReload?: boolean;
  /** Enable debug logging */
  debug?: boolean;
}

/**
 * Hook for subscribing to Echo private channels with Inertia integration.
 *
 * Automatically:
 * - Subscribes to the channel on mount
 * - Reloads Inertia props when events are received
 * - Cleans up subscription on unmount
 *
 * @example
 * ```tsx
 * // Listen for new messages and auto-reload
 * useEcho({
 *   channel: `bot.${botId}`,
 *   event: 'NewMessage',
 *   reloadOnly: ['messages', 'conversations'],
 * });
 *
 * // Custom callback without auto-reload
 * useEcho({
 *   channel: `conversation.${conversationId}`,
 *   event: 'MessageSent',
 *   autoReload: false,
 *   onEvent: (data) => console.log('New message:', data),
 * });
 * ```
 */
export function useEcho({
  channel,
  event,
  onEvent,
  reloadOnly,
  autoReload = true,
  debug = false,
}: UseEchoOptions): void {
  const channelRef = useRef<Channel | null>(null);

  const log = useCallback(
    (...args: unknown[]) => {
      if (debug) {
        console.log(`[useEcho:${channel}]`, ...args);
      }
    },
    [channel, debug]
  );

  useEffect(() => {
    // Don't subscribe if no channel name
    if (!channel) {
      log('No channel name provided, skipping subscription');
      return;
    }

    const echo = getEcho();

    log(`Subscribing to private channel: ${channel}, event: ${event}`);

    // Subscribe to private channel
    channelRef.current = echo.private(channel);

    // Listen for the event
    channelRef.current.listen(`.${event}`, (data: unknown) => {
      log(`Received event: ${event}`, data);

      // Call custom callback if provided
      if (onEvent) {
        onEvent(data);
      }

      // Auto-reload Inertia props
      if (autoReload) {
        log('Reloading Inertia props:', reloadOnly || 'all');
        router.reload({
          only: reloadOnly,
          preserveScroll: true,
          preserveState: true,
        });
      }
    });

    // Cleanup on unmount
    return () => {
      log(`Unsubscribing from channel: ${channel}`);
      echo.leave(channel);
      channelRef.current = null;
    };
  }, [channel, event, onEvent, reloadOnly, autoReload, log]);
}

interface UseEchoMultipleOptions {
  /** Array of channel subscriptions */
  subscriptions: Array<{
    channel: string;
    event: string;
    onEvent?: (data: unknown) => void;
  }>;
  /** Inertia props to reload on any event */
  reloadOnly?: string[];
  /** Whether to auto-reload (default: true) */
  autoReload?: boolean;
  /** Enable debug logging */
  debug?: boolean;
}

/**
 * Hook for subscribing to multiple Echo channels at once.
 * Useful when you need to listen to several channels simultaneously.
 *
 * @example
 * ```tsx
 * useEchoMultiple({
 *   subscriptions: [
 *     { channel: `bot.${botId}`, event: 'NewMessage' },
 *     { channel: `bot.${botId}`, event: 'ConversationUpdated' },
 *     { channel: `user.${userId}`, event: 'NotificationReceived' },
 *   ],
 *   reloadOnly: ['conversations', 'messages', 'notifications'],
 * });
 * ```
 */
export function useEchoMultiple({
  subscriptions,
  reloadOnly,
  autoReload = true,
  debug = false,
}: UseEchoMultipleOptions): void {
  const channelsRef = useRef<Map<string, Channel>>(new Map());

  const log = useCallback(
    (...args: unknown[]) => {
      if (debug) {
        console.log('[useEchoMultiple]', ...args);
      }
    },
    [debug]
  );

  useEffect(() => {
    if (!subscriptions.length) return;

    const echo = getEcho();

    // Subscribe to all channels
    subscriptions.forEach(({ channel, event, onEvent }) => {
      if (!channel) return;

      // Get or create channel subscription
      let echoChannel = channelsRef.current.get(channel);
      if (!echoChannel) {
        log(`Subscribing to channel: ${channel}`);
        echoChannel = echo.private(channel);
        channelsRef.current.set(channel, echoChannel);
      }

      // Listen for event
      log(`Listening for event: ${event} on channel: ${channel}`);
      echoChannel.listen(`.${event}`, (data: unknown) => {
        log(`Received ${event} on ${channel}:`, data);

        if (onEvent) {
          onEvent(data);
        }

        if (autoReload) {
          router.reload({
            only: reloadOnly,
            preserveScroll: true,
            preserveState: true,
          });
        }
      });
    });

    // Cleanup
    return () => {
      channelsRef.current.forEach((_, channel) => {
        log(`Leaving channel: ${channel}`);
        echo.leave(channel);
      });
      channelsRef.current.clear();
    };
  }, [subscriptions, reloadOnly, autoReload, log]);
}

/**
 * Hook for listening to Echo connection state changes.
 * Useful for showing connection status indicators.
 */
export function useEchoConnection(options?: {
  onConnected?: () => void;
  onReconnected?: () => void;
  onDisconnected?: (state: string) => void;
}): void {
  useEffect(() => {
    const handleConnected = () => {
      options?.onConnected?.();
    };

    const handleReconnected = () => {
      options?.onReconnected?.();
    };

    const handleDisconnected = (event: CustomEvent<{ state: string }>) => {
      options?.onDisconnected?.(event.detail.state);
    };

    window.addEventListener('echo:connected', handleConnected);
    window.addEventListener('echo:reconnected', handleReconnected);
    window.addEventListener('echo:disconnected', handleDisconnected as EventListener);

    return () => {
      window.removeEventListener('echo:connected', handleConnected);
      window.removeEventListener('echo:reconnected', handleReconnected);
      window.removeEventListener('echo:disconnected', handleDisconnected as EventListener);
    };
  }, [options]);
}

export default useEcho;
