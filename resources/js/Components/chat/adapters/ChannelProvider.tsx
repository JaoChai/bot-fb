/**
 * Channel Provider Context
 * React Context for providing channel adapter based on conversation.channel_type
 */
import { createContext, useContext, useMemo, type ReactNode } from 'react';
import type { ChannelAdapter, ChannelAdapterType } from './types';
import { defaultAdapter } from './types';
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
  channelType: ChannelAdapterType | null;
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
 */
export function useChannel(): ChannelContextValue {
  return useContext(ChannelContext);
}

/**
 * Hook to get channel adapter directly (without context)
 */
export function useChannelAdapter(channelType: string | null | undefined): ChannelAdapter {
  return useMemo(() => getChannelAdapter(channelType), [channelType]);
}
