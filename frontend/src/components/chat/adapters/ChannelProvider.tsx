/**
 * T050: Channel Provider Context
 * React Context for providing channel adapter based on conversation.channel_type
 */
import { createContext, useContext, useMemo, type ReactNode } from 'react';
import type { ChannelAdapter, ChannelType } from './ChannelAdapter';
import { defaultAdapter } from './ChannelAdapter';
import { lineAdapter } from './LineAdapter';
import { telegramAdapter } from './TelegramAdapter';
import { facebookAdapter } from './FacebookAdapter';

/**
 * Map of channel types to adapters
 */
const adapters: Record<string, ChannelAdapter> = {
  line: lineAdapter,
  telegram: telegramAdapter,
  facebook: facebookAdapter,
};

/**
 * Get adapter for a channel type
 */
export function getChannelAdapter(channelType: string | null | undefined): ChannelAdapter {
  if (!channelType) return defaultAdapter;
  return adapters[channelType] || defaultAdapter;
}

/**
 * Channel context value type
 */
interface ChannelContextValue {
  adapter: ChannelAdapter;
  channelType: ChannelType | null;
}

/**
 * Channel context
 */
const ChannelContext = createContext<ChannelContextValue>({
  adapter: defaultAdapter,
  channelType: null,
});

/**
 * Channel Provider Props
 */
interface ChannelProviderProps {
  channelType: string | null | undefined;
  children: ReactNode;
}

/**
 * Channel Provider component
 * Wraps components that need access to channel-specific functionality
 *
 * @example
 * ```tsx
 * <ChannelProvider channelType={conversation.channel_type}>
 *   <MessageList />
 * </ChannelProvider>
 * ```
 */
export function ChannelProvider({ channelType, children }: ChannelProviderProps) {
  const value = useMemo<ChannelContextValue>(() => {
    const adapter = getChannelAdapter(channelType);
    return {
      adapter,
      channelType: adapter.name,
    };
  }, [channelType]);

  return (
    <ChannelContext.Provider value={value}>
      {children}
    </ChannelContext.Provider>
  );
}

/**
 * Hook to access channel adapter from context
 * Falls back to default adapter if not within ChannelProvider
 *
 * @example
 * ```tsx
 * function MessageBubble({ message }) {
 *   const { adapter, channelType } = useChannel();
 *
 *   return (
 *     <div>
 *       {adapter.renderMessageContent(message)}
 *     </div>
 *   );
 * }
 * ```
 */
export function useChannel(): ChannelContextValue {
  const context = useContext(ChannelContext);
  return context;
}

/**
 * Hook to get channel adapter directly (without context)
 * Useful when you have the channel type available directly
 *
 * @example
 * ```tsx
 * function ChatWindow({ conversation }) {
 *   const adapter = useChannelAdapter(conversation.channel_type);
 *   // ...
 * }
 * ```
 */
export function useChannelAdapter(channelType: string | null | undefined): ChannelAdapter {
  return useMemo(() => getChannelAdapter(channelType), [channelType]);
}
