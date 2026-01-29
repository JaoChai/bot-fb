/**
 * Chat input area component
 * Handles different input states (closed, telegram, line, handover, bot-only)
 *
 * Uses useChatInputState hook for state machine logic.
 */
import { useState, useCallback } from 'react';
import { Bot } from 'lucide-react';
import { MessageInput } from './MessageInput';
import { TelegramMessageInput } from '@/components/telegram/TelegramMessageInput';
import { LINEMessageInput } from '@/components/line/LINEMessageInput';
import { useChatInputState } from '@/hooks/useChatInputState';
import type { Conversation } from '@/types/api';
import type { QuickReply } from '@/types/quick-reply';

interface ChatInputAreaProps {
  conversation: Conversation;
  onSendMessage: (content: string) => Promise<void>;
  onSendWithMedia: (content: string, media: File | null) => Promise<void>;
  onQuickReplySelect: (quickReply: QuickReply) => Promise<void>;
  isSending: boolean;
}

export function ChatInputArea({
  conversation,
  onSendMessage,
  onSendWithMedia,
  onQuickReplySelect,
  isSending,
}: ChatInputAreaProps) {
  const [messageInput, setMessageInput] = useState('');
  const [selectedMedia, setSelectedMedia] = useState<File | null>(null);

  // State machine for input states
  const inputState = useChatInputState(conversation);

  const handleChannelSubmit = useCallback(
    async (e: React.FormEvent) => {
      e.preventDefault();
      const content = messageInput.trim();
      if (content || selectedMedia) {
        await onSendWithMedia(content, selectedMedia);
        setMessageInput('');
        setSelectedMedia(null);
      }
    },
    [messageInput, selectedMedia, onSendWithMedia]
  );

  // Render based on state type
  switch (inputState.type) {
    case 'closed':
    case 'bot_active':
      return (
        <div className="flex-shrink-0 border-t bg-background">
          <div className="p-4 text-center text-sm text-muted-foreground">
            {inputState.type === 'bot_active' && (
              <Bot className="h-4 w-4 inline-block mr-1" />
            )}
            {inputState.disabledMessage}
          </div>
        </div>
      );

    case 'telegram':
      return (
        <div className="flex-shrink-0 border-t bg-background">
          <TelegramMessageInput
            value={messageInput}
            onChange={setMessageInput}
            selectedMedia={selectedMedia}
            onMediaSelect={setSelectedMedia}
            onSubmit={handleChannelSubmit}
            isLoading={isSending}
          />
        </div>
      );

    case 'line_handover':
      return (
        <div className="flex-shrink-0 border-t bg-background">
          <LINEMessageInput
            value={messageInput}
            onChange={setMessageInput}
            selectedMedia={selectedMedia}
            onMediaSelect={setSelectedMedia}
            onSubmit={handleChannelSubmit}
            isLoading={isSending}
            onQuickReplySelect={onQuickReplySelect}
            showQuickReply={inputState.showQuickReply}
          />
        </div>
      );

    case 'handover':
      return (
        <div className="flex-shrink-0 border-t bg-background">
          <MessageInput
            onSend={onSendMessage}
            isLoading={isSending}
            isHandover={true}
            placeholder="Type a message or / for quick reply..."
          />
        </div>
      );

    default:
      // Exhaustive check - TypeScript will error if we miss a case
      const _exhaustive: never = inputState.type;
      return _exhaustive;
  }
}
