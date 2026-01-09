/**
 * Chat input area component
 * Handles different input states (closed, telegram, line, handover, bot-only)
 * Extracted from ChatWindow.tsx
 */
import { useState, useCallback } from 'react';
import { Bot } from 'lucide-react';
import { MessageInput } from './MessageInput';
import { TelegramMessageInput } from '@/components/telegram/TelegramMessageInput';
import { LINEMessageInput } from '@/components/line/LINEMessageInput';
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

  const isTelegram = conversation.channel_type === 'telegram';
  const isLINE = conversation.channel_type === 'line';

  const handleChannelSubmit = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    const content = messageInput.trim();
    if (content || selectedMedia) {
      await onSendWithMedia(content, selectedMedia);
      setMessageInput('');
      setSelectedMedia(null);
    }
  }, [messageInput, selectedMedia, onSendWithMedia]);

  // Closed conversation
  if (conversation.status === 'closed') {
    return (
      <div className="border-t bg-background">
        <div className="p-4 text-center text-sm text-muted-foreground">
          This conversation is closed
        </div>
      </div>
    );
  }

  // Telegram channel
  if (isTelegram) {
    return (
      <div className="border-t bg-background">
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
  }

  // LINE channel with handover
  if (isLINE && conversation.is_handover) {
    return (
      <div className="border-t bg-background">
        <LINEMessageInput
          value={messageInput}
          onChange={setMessageInput}
          selectedMedia={selectedMedia}
          onMediaSelect={setSelectedMedia}
          onSubmit={handleChannelSubmit}
          isLoading={isSending}
          onQuickReplySelect={onQuickReplySelect}
          showQuickReply={true}
        />
      </div>
    );
  }

  // Regular handover mode
  if (conversation.is_handover) {
    return (
      <div className="border-t bg-background">
        <MessageInput
          onSend={onSendMessage}
          isLoading={isSending}
          isHandover={conversation.is_handover}
          placeholder="Type a message or / for quick reply..."
        />
      </div>
    );
  }

  // Bot is handling
  return (
    <div className="border-t bg-background">
      <div className="p-4 text-center text-sm text-muted-foreground">
        <Bot className="h-4 w-4 inline-block mr-1" />
        Bot is handling this conversation. Click "Take Over" to respond manually.
      </div>
    </div>
  );
}
