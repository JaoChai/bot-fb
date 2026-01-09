/**
 * Channel Adapter Types & Interface
 * Strategy pattern for channel-specific message rendering and handling
 */
import type { ReactNode } from 'react';
import type { Message, Customer } from '@/types';

/**
 * Media upload configuration for each channel
 */
export interface MediaUploadConfig {
  maxSize: number; // bytes
  formats: string[]; // file extensions
  maxDuration?: number; // seconds for video/audio
}

/**
 * Outgoing message payload structure
 */
export interface OutgoingPayload {
  type: 'text' | 'image' | 'video' | 'audio' | 'file' | 'sticker' | 'location';
  content?: string;
  mediaUrl?: string;
  metadata?: Record<string, unknown>;
}

/**
 * Channel type for adapter identification
 */
export type ChannelAdapterType = 'line' | 'telegram' | 'facebook';

/**
 * Message with extended metadata for rendering
 */
export interface RenderableMessage extends Message {
  type?: string;
  sender?: 'user' | 'bot' | 'agent';
  media_url?: string;
  media_metadata?: Record<string, unknown>;
}

/**
 * Channel Adapter interface
 * Implementations provide channel-specific rendering and message handling
 */
export interface ChannelAdapter {
  /** Channel identifier */
  name: ChannelAdapterType;

  /** Brand color for UI theming */
  brandColor: string;

  /**
   * Render message content with channel-specific styling
   * @param message - Message to render
   * @param options - Additional rendering options
   */
  renderMessageContent: (
    message: RenderableMessage,
    options?: {
      onImageClick?: (url: string) => void;
    }
  ) => ReactNode;

  /**
   * Render customer avatar with channel-specific styling
   * @param customer - Customer profile
   * @param size - Avatar size
   */
  renderAvatar: (
    customer: Customer | null,
    size?: 'sm' | 'md' | 'lg'
  ) => ReactNode;

  /**
   * Get media upload configuration for the channel
   */
  getMediaUploadConfig: () => MediaUploadConfig;

  /**
   * Format outgoing message content for the channel
   * @param content - Text content
   * @param type - Message type
   */
  formatOutgoingMessage: (
    content: string,
    type?: 'text' | 'image' | 'video' | 'audio' | 'file'
  ) => OutgoingPayload;

  /**
   * Check if this channel supports a specific feature
   * @param feature - Feature name
   */
  supportsFeature: (
    feature: 'sticker' | 'location' | 'voice' | 'contact' | 'poll' | 'quickReply'
  ) => boolean;
}

/**
 * Default adapter for unknown channels
 * Provides basic rendering without channel-specific features
 */
export const defaultAdapter: ChannelAdapter = {
  name: 'facebook',
  brandColor: '#0084FF',

  renderMessageContent: (message) => message.content,

  renderAvatar: () => null,

  getMediaUploadConfig: () => ({
    maxSize: 25 * 1024 * 1024, // 25MB
    formats: ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'pdf'],
  }),

  formatOutgoingMessage: (content, type = 'text') => ({
    type,
    content,
  }),

  supportsFeature: (feature) => {
    // Facebook supports basic features
    return ['sticker', 'quickReply'].includes(feature);
  },
};
