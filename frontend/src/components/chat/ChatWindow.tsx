/**
 * T024: Refactored ChatWindow component
 * Container/orchestration component using extracted sub-components
 * Reduced from ~368 lines to ~100 lines
 */
import { useState } from 'react';
import { useMessages } from '@/hooks/chat';
import { useChatActions } from '@/hooks/useChatActions';
import type { Conversation } from '@/types/api';

// Extracted components
import { ChatHeader } from './ChatHeader';
import { MessageList } from './MessageList';
import { ClearContextDialog } from './ClearContextDialog';
import { ChannelMessageArea } from './ChannelMessageArea';
import { ChatInputArea } from './ChatInputArea';

interface ChatWindowProps {
  botId: number;
  conversation: Conversation;
  onShowInfo: () => void;
  onBack?: () => void;
}

export function ChatWindow({ botId, conversation, onShowInfo, onBack }: ChatWindowProps) {
  const [autoScroll, setAutoScroll] = useState(true);

  // Channel detection
  const isTelegram = conversation.channel_type === 'telegram';
  const isLINE = conversation.channel_type === 'line';
  const useCustomBubbles = isTelegram || isLINE;

  // Messages query - use useMessages for consistent query keys with WebSocket updates
  const { data: messagesResponse, isLoading: isLoadingMessages, isFetching: isFetchingMessages } = useMessages(
    botId,
    conversation.id,
    { order: 'asc', perPage: 100 }
  );
  const messages = messagesResponse?.data || conversation.messages || [];
  const showMessagesLoading = (isLoadingMessages || isFetchingMessages) && messages.length === 0;

  // Chat actions from custom hook
  const {
    handleSendMessage,
    handleSendWithMedia,
    handleToggleHandover,
    handleClearContext,
    handleQuickReplySelect,
    isSending,
    isTogglingHandover,
    isClearingContext,
  } = useChatActions({ botId, conversation });

  // Clear context button (rendered in header actions)
  const clearContextButton = isTelegram ? null : (
    <ClearContextDialog
      onClearContext={handleClearContext}
      isPending={isClearingContext}
    />
  );

  return (
    <div className="flex flex-col h-full min-h-0 overflow-hidden">
      {/* Header */}
      <ChatHeader
        conversation={conversation}
        onBack={onBack}
        onShowInfo={onShowInfo}
        onToggleHandover={handleToggleHandover}
        isToggleLoading={isTogglingHandover}
        showHandoverControls={!isTelegram}
        actions={clearContextButton}
      />

      {/* Messages Area */}
      {useCustomBubbles ? (
        <ChannelMessageArea
          messages={messages}
          conversation={conversation}
          isLoading={showMessagesLoading}
          channelType={isTelegram ? 'telegram' : 'line'}
        />
      ) : (
        <MessageList
          messages={messages}
          isLoading={showMessagesLoading}
          contextClearedAt={conversation.context_cleared_at}
          conversationCreatedAt={conversation.created_at}
          autoScroll={autoScroll}
          onAutoScrollChange={setAutoScroll}
        />
      )}

      {/* Footer - Chat Input */}
      <ChatInputArea
        conversation={conversation}
        onSendMessage={handleSendMessage}
        onSendWithMedia={handleSendWithMedia}
        onQuickReplySelect={handleQuickReplySelect}
        isSending={isSending}
      />
    </div>
  );
}
