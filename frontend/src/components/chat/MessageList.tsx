/**
 * T021: MessageList component
 * List of MessageBubble components with auto-scroll
 */
import { useRef, useEffect, useCallback, useMemo, memo } from 'react';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Button } from '@/components/ui/button';
import { Loader2, ChevronDown, RotateCcw } from 'lucide-react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { MessageBubble } from './MessageBubble';
import type { Message } from '@/types/api';

export interface MessageListProps {
  messages: Message[];
  isLoading?: boolean;
  contextClearedAt?: string | null;
  conversationCreatedAt?: string;
  autoScroll?: boolean;
  onAutoScrollChange?: (autoScroll: boolean) => void;
}

// Memoized message item with context separator
const MemoizedMessageItem = memo(function MemoizedMessageItem({
  message,
  previousMessage,
  contextClearedAt,
}: {
  message: Message;
  previousMessage?: Message;
  contextClearedAt: Date | null;
}) {
  const showContextSeparator = useMemo(() => {
    if (!contextClearedAt) return false;
    const messageTime = new Date(message.created_at);
    const previousMessageTime = previousMessage
      ? new Date(previousMessage.created_at)
      : null;
    return (
      (!previousMessageTime && messageTime >= contextClearedAt) ||
      (previousMessageTime &&
        previousMessageTime < contextClearedAt &&
        messageTime >= contextClearedAt)
    );
  }, [message.created_at, previousMessage?.created_at, contextClearedAt]);

  return (
    <div>
      {showContextSeparator && contextClearedAt && (
        <div className="flex items-center gap-3 py-3 my-2">
          <div className="flex-1 h-px bg-border" />
          <div className="flex items-center gap-2 text-xs text-muted-foreground bg-muted px-3 py-1 rounded-full border">
            <RotateCcw className="h-3 w-3" />
            <span>
              Bot context reset - {format(contextClearedAt, 'PPp', { locale: th })}
            </span>
          </div>
          <div className="flex-1 h-px bg-border" />
        </div>
      )}
      <MessageBubble message={message} previousMessage={previousMessage} />
    </div>
  );
});

export function MessageList({
  messages,
  isLoading = false,
  contextClearedAt,
  conversationCreatedAt,
  autoScroll: externalAutoScroll,
  onAutoScrollChange,
}: MessageListProps) {
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const scrollViewportRef = useRef<HTMLDivElement>(null);
  const internalAutoScroll = useRef(true);

  // Use external state if provided, otherwise use internal
  const autoScroll = externalAutoScroll ?? internalAutoScroll.current;

  // Memoize contextClearedAt as Date
  const contextClearedAtDate = useMemo(
    () => (contextClearedAt ? new Date(contextClearedAt) : null),
    [contextClearedAt]
  );

  // Auto scroll to bottom when messages change
  useEffect(() => {
    if (autoScroll && messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages, autoScroll]);

  // Handle scroll to detect when user scrolls to bottom
  const handleScroll = useCallback(
    (e: React.UIEvent<HTMLDivElement>) => {
      const target = e.currentTarget;
      const isAtBottom = target.scrollHeight - target.scrollTop - target.clientHeight < 50;

      if (isAtBottom !== autoScroll) {
        internalAutoScroll.current = isAtBottom;
        onAutoScrollChange?.(isAtBottom);
      }
    },
    [autoScroll, onAutoScrollChange]
  );

  // Handle scroll to bottom button click
  const handleScrollToBottom = useCallback(() => {
    internalAutoScroll.current = true;
    onAutoScrollChange?.(true);
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [onAutoScrollChange]);

  if (isLoading) {
    return (
      <div className="flex-1 flex items-center justify-center py-8">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (messages.length === 0) {
    return (
      <div className="flex-1 flex items-center justify-center text-center py-8 text-muted-foreground">
        No messages in this conversation yet
      </div>
    );
  }

  return (
    <div className="relative flex-1 min-h-0">
      <ScrollArea
        className="h-full p-4"
        viewportRef={scrollViewportRef}
        onScroll={handleScroll}
      >
        <div className="space-y-4 max-w-3xl mx-auto">
          {/* Conversation start indicator */}
          {conversationCreatedAt && (
            <div className="text-center text-sm text-muted-foreground py-2">
              <span className="bg-muted px-3 py-1 rounded-full text-xs">
                Started {format(new Date(conversationCreatedAt), 'PPp', { locale: th })}
              </span>
            </div>
          )}

          {/* Messages */}
          {messages.map((message, index) => (
            <MemoizedMessageItem
              key={message.id}
              message={message}
              previousMessage={index > 0 ? messages[index - 1] : undefined}
              contextClearedAt={contextClearedAtDate}
            />
          ))}

          {/* Scroll anchor */}
          <div ref={messagesEndRef} />
        </div>
      </ScrollArea>

      {/* Scroll to bottom button */}
      {!autoScroll && (
        <Button
          variant="secondary"
          size="sm"
          className="absolute bottom-4 left-1/2 -translate-x-1/2 shadow-lg z-20"
          onClick={handleScrollToBottom}
        >
          <ChevronDown className="h-4 w-4 mr-2" />
          New messages
        </Button>
      )}
    </div>
  );
}

export default MessageList;
