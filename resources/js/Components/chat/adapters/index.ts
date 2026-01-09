/**
 * Channel Adapters - Export barrel file
 * Strategy pattern for channel-specific message rendering
 */

// Core types and interface
export type {
  ChannelAdapter,
  ChannelAdapterType,
  MediaUploadConfig,
  OutgoingPayload,
  RenderableMessage,
} from './types';
export { defaultAdapter } from './types';

// Channel-specific adapters
export { lineAdapter } from './LineAdapter';
export { telegramAdapter } from './TelegramAdapter';
export { facebookAdapter } from './FacebookAdapter';

// Context and hooks
export {
  ChannelProvider,
  useChannel,
  useChannelAdapter,
  getChannelAdapter,
} from './ChannelProvider';
