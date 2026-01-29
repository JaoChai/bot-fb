/**
 * useChannelInfo - Centralized channel detection hook
 *
 * Consolidates channel detection logic that was previously duplicated
 * across 33+ locations in the codebase.
 *
 * @example
 * const { isTelegram, isLINE, isGroup, supportsHandover } = useChannelInfo(conversation);
 */
import { useMemo } from 'react';
import type { Conversation } from '@/types/api';

export type ChannelType = 'line' | 'telegram' | 'facebook' | 'demo' | null;

export interface ChannelInfo {
  /** Raw channel type from conversation */
  channelType: ChannelType;

  /** Channel detection booleans */
  isTelegram: boolean;
  isLINE: boolean;
  isFacebook: boolean;
  isDemo: boolean;

  /** Telegram group detection (group or supergroup) */
  isGroup: boolean;

  /** Telegram private chat */
  isPrivateChat: boolean;

  /** Whether the channel supports handover mode (LINE and Facebook only) */
  supportsHandover: boolean;

  /** Whether the channel supports media messages */
  supportsMedia: boolean;

  /** Whether the channel uses custom bubble rendering */
  useCustomBubbles: boolean;

  /** Human-readable channel name */
  displayName: string;
}

/**
 * Default channel info for undefined conversation
 */
const defaultChannelInfo: ChannelInfo = {
  channelType: null,
  isTelegram: false,
  isLINE: false,
  isFacebook: false,
  isDemo: false,
  isGroup: false,
  isPrivateChat: false,
  supportsHandover: false,
  supportsMedia: false,
  useCustomBubbles: false,
  displayName: 'Unknown',
};

/**
 * Compute channel information from a conversation (internal helper)
 * Single source of truth for channel detection logic
 */
function computeChannelInfo(
  conversation: Conversation | undefined
): ChannelInfo {
  if (!conversation) {
    return defaultChannelInfo;
  }

  const channelType = conversation.channel_type as ChannelType;

  // Channel detection
  const isTelegram = channelType === 'telegram';
  const isLINE = channelType === 'line';
  const isFacebook = channelType === 'facebook';
  const isDemo = channelType === 'demo';

  // Telegram-specific: group detection
  const telegramChatType = conversation.telegram_chat_type;
  const isGroup =
    isTelegram &&
    (telegramChatType === 'group' || telegramChatType === 'supergroup');
  const isPrivateChat = isTelegram && telegramChatType === 'private';

  // Feature support
  // LINE and Facebook support handover mode, Telegram does not
  const supportsHandover = isLINE || isFacebook;

  // All channels except demo support media
  const supportsMedia = !isDemo;

  // LINE and Telegram use custom bubble rendering
  const useCustomBubbles = isTelegram || isLINE;

  // Display name for UI
  const displayName = getDisplayName(channelType);

  return {
    channelType,
    isTelegram,
    isLINE,
    isFacebook,
    isDemo,
    isGroup,
    isPrivateChat,
    supportsHandover,
    supportsMedia,
    useCustomBubbles,
    displayName,
  };
}

/**
 * Get channel information from a conversation (React hook)
 *
 * @param conversation - The conversation object (can be undefined)
 * @returns ChannelInfo object with all channel detection utilities
 */
export function useChannelInfo(
  conversation: Conversation | undefined
): ChannelInfo {
  return useMemo(
    () => computeChannelInfo(conversation),
    [conversation?.channel_type, conversation?.telegram_chat_type]
  );
}

/**
 * Get human-readable display name for channel type
 */
function getDisplayName(channelType: ChannelType): string {
  switch (channelType) {
    case 'line':
      return 'LINE';
    case 'telegram':
      return 'Telegram';
    case 'facebook':
      return 'Facebook';
    case 'demo':
      return 'Demo';
    default:
      return 'Unknown';
  }
}

/**
 * Non-hook version for use outside React components
 * Use this in utility functions or callbacks
 */
export function getChannelInfo(
  conversation: Conversation | undefined
): ChannelInfo {
  return computeChannelInfo(conversation);
}
