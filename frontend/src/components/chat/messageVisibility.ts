import type { Message } from '@/types/api';

/**
 * True when a message should render in the admin chat message list.
 *
 * Hides bot text messages that carry no content and no media — e.g. the
 * Support Router's duplicate-suppressed rows (content = '', no media_url) —
 * which would otherwise render as a blank bubble. Everything else renders
 * exactly as before: user messages (even empty), agent messages, bot
 * messages with content, and any message with media or a non-text type.
 */
export function isVisibleMessage(message: Message): boolean {
  return !(
    message.sender === 'bot' &&
    (message.type == null || message.type === 'text') &&
    !message.media_url &&
    !message.content?.trim()
  );
}

/**
 * The filtered list must drive every downstream read (list count,
 * previousMessage, timestamp separators, auto-scroll and the load-older
 * anchor), so hidden rows never leave a gap in any of them.
 */
export function filterVisibleMessages(messages: Message[]): Message[] {
  return messages.filter(isVisibleMessage);
}
