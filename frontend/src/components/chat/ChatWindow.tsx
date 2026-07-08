/**
 * T024: Refactored ChatWindow component
 * Container/orchestration component using extracted sub-components
 * Reduced from ~368 lines to ~100 lines
 */
import { useState, useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useMessages, messageKeys } from '@/hooks/chat';
import { useConfirmPayment } from '@/hooks/chat/useConfirmPayment';
import { useChatActions } from '@/hooks/useChatActions';
import { useChannelInfo } from '@/hooks/useChannelInfo';
import { useBotSettings } from '@/hooks/useBotSettings';
import type { Conversation } from '@/types/api';

// Extracted components
import { ChatHeader } from './ChatHeader';
import { MessageList } from './MessageList';
import { ClearContextDialog } from './ClearContextDialog';
import { ConfirmPaymentDialog } from './ConfirmPaymentDialog';
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

  // Channel detection - using centralized hook
  const { isTelegram, isLINE, useCustomBubbles, supportsHandover } = useChannelInfo(conversation);

  // Bot settings drive slip-verification-only affordances (manual payment confirm)
  const { data: botSettings } = useBotSettings(botId);
  const confirmPayment = useConfirmPayment(botId);

  // Messages query - use useMessages for consistent query keys with WebSocket updates
  const { data: messagesResponse, isLoading: isLoadingMessages, isFetching: isFetchingMessages } = useMessages(
    botId,
    conversation.id,
    { order: 'asc', perPage: 100 }
  );
  const messages = messagesResponse?.data || conversation.messages || [];
  const showMessagesLoading = (isLoadingMessages || isFetchingMessages) && messages.length === 0;

  // Invalidate messages query when switching conversations to force refetch
  const queryClient = useQueryClient();
  useEffect(() => {
    queryClient.invalidateQueries({
      queryKey: messageKeys.list(botId, conversation.id),
    });
  }, [conversation.id, botId, queryClient]);

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

  // Clear context button (rendered in header actions) - Telegram doesn't support context clearing
  const clearContextButton = isTelegram ? null : (
    <ClearContextDialog
      onClearContext={handleClearContext}
      isPending={isClearingContext}
    />
  );

  // Manual payment confirm - LINE conversations of slip-verification-enabled bots only
  const showConfirmPayment = isLINE && Boolean(botSettings?.slip_verification_enabled);
  const headerActions = (
    <>
      {showConfirmPayment && (
        <ConfirmPaymentDialog
          onConfirm={(amount) =>
            confirmPayment.mutateAsync({ conversationId: conversation.id, amount })
          }
          isPending={confirmPayment.isPending}
        />
      )}
      {clearContextButton}
    </>
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
        showHandoverControls={supportsHandover}
        actions={headerActions}
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
