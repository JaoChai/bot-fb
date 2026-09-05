/**
 * T050: Channel Provider Context
 * React Context for providing channel adapter based on conversation.channel_type
 */
import { createContext, useContext } from 'react';
import type { ChannelAdapter, ChannelType } from './ChannelAdapter';
import { defaultAdapter } from './ChannelAdapter';

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
 * Hook to access channel adapter from context
 * Falls back to the default adapter when no provider is present
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
