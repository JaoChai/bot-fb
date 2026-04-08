/**
 * Chat input state machine hook
 *
 * Determines the input state based on conversation status and channel type.
 * Replaces if-else chain in ChatInputArea with explicit state machine.
 *
 * States:
 * - closed: Conversation is closed, no input allowed
 * - telegram: Telegram channel, always show Telegram input
 * - line_handover: LINE channel in handover mode
 * - handover: Generic handover mode (Facebook or other)
 * - bot_active: Bot is handling, agent cannot send
 */
import { useMemo } from 'react';
import { useChannelInfo } from './useChannelInfo';
import type { Conversation } from '@/types/api';

export type InputStateType =
  | 'closed'
  | 'telegram'
  | 'line_handover'
  | 'handover'
  | 'bot_active';

export interface InputState {
  /** Current state type */
  type: InputStateType;
  /** Whether agent can send messages */
  canSendMessage: boolean;
  /** Whether to show quick reply options */
  showQuickReply: boolean;
  /** Whether to show media upload */
  showMediaUpload: boolean;
  /** Message to display when input is disabled */
  disabledMessage: string | null;
}

const STATE_CONFIG: Record<InputStateType, Omit<InputState, 'type'>> = {
  closed: {
    canSendMessage: false,
    showQuickReply: false,
    showMediaUpload: false,
    disabledMessage: 'This conversation is closed',
  },
  telegram: {
    canSendMessage: true,
    showQuickReply: false,
    showMediaUpload: true,
    disabledMessage: null,
  },
  line_handover: {
    canSendMessage: true,
    showQuickReply: true,
    showMediaUpload: true,
    disabledMessage: null,
  },
  handover: {
    canSendMessage: true,
    showQuickReply: true,
    showMediaUpload: false,
    disabledMessage: null,
  },
  bot_active: {
    canSendMessage: false,
    showQuickReply: false,
    showMediaUpload: false,
    disabledMessage:
      'Bot is handling this conversation. Click "Take Over" to respond manually.',
  },
};

/**
 * Determines the input state for a conversation.
 *
 * State machine transitions:
 * 1. If closed -> 'closed'
 * 2. If Telegram -> 'telegram' (always allow input)
 * 3. If LINE + handover -> 'line_handover'
 * 4. If handover -> 'handover'
 * 5. Otherwise -> 'bot_active'
 */
export function useChatInputState(
  conversation: Conversation | undefined
): InputState {
  const { isTelegram, isLINE } = useChannelInfo(conversation);

  return useMemo(() => {
    // No conversation yet
    if (!conversation) {
      return {
        type: 'closed' as const,
        ...STATE_CONFIG.closed,
      };
    }

    // State machine transitions
    let stateType: InputStateType;

    if (conversation.status === 'closed') {
      stateType = 'closed';
    } else if (isTelegram) {
      stateType = 'telegram';
    } else if (isLINE && conversation.is_handover) {
      stateType = 'line_handover';
    } else if (conversation.is_handover) {
      stateType = 'handover';
    } else {
      stateType = 'bot_active';
    }

    return {
      type: stateType,
      ...STATE_CONFIG[stateType],
    };
  }, [conversation, isTelegram, isLINE]);
}
