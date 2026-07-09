/**
 * Channel-specific message area component
 * Handles Telegram and LINE message rendering
 * Extracted from ChatWindow.tsx
 */
import { useState, useRef, useCallback, useEffect, useLayoutEffect } from 'react';
import { format, isValid } from 'date-fns';
import { th } from 'date-fns/locale';
import { ChevronDown, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { TelegramMessageBubble } from '@/components/telegram/TelegramMessageBubble';
import { LINEMessageBubble } from '@/components/line/LINEMessageBubble';
import type { Message, Conversation } from '@/types/api';

interface ChannelMessageAreaProps {
  messages: Message[];
  conversation: Conversation;
  isLoading: boolean;
  channelType: 'telegram' | 'line';
  hasOlder?: boolean;
  isLoadingOlder?: boolean;
  onLoadOlder?: () => void;
}

export function ChannelMessageArea({
  messages,
  conversation,
  isLoading,
  channelType,
  hasOlder = false,
  isLoadingOlder = false,
  onLoadOlder,
}: ChannelMessageAreaProps) {
  const [autoScroll, setAutoScroll] = useState(true);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const scrollViewportRef = useRef<HTMLDivElement>(null);

  // Scroll anchoring for load-older: captured when the fetch is triggered,
  // applied after the older page is prepended so the view doesn't jump.
  const loadOlderAnchorRef = useRef<{ scrollTop: number; scrollHeight: number } | null>(null);
  const prevFirstIdRef = useRef<number | null>(null);

  // Auto scroll to bottom when messages change
  useEffect(() => {
    if (autoScroll && messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages, autoScroll]);

  // Restore scroll position after older messages are prepended
  useLayoutEffect(() => {
    const firstId = messages[0]?.id ?? null;
    const anchor = loadOlderAnchorRef.current;
    const viewport = scrollViewportRef.current;

    if (anchor && viewport && prevFirstIdRef.current !== null && firstId !== prevFirstIdRef.current) {
      viewport.scrollTop = anchor.scrollTop + (viewport.scrollHeight - anchor.scrollHeight);
      loadOlderAnchorRef.current = null;
    } else if (anchor && !isLoadingOlder && firstId === prevFirstIdRef.current) {
      // Fetch settled without new messages (error / empty page) — release the anchor
      loadOlderAnchorRef.current = null;
    }

    prevFirstIdRef.current = firstId;
  }, [messages, isLoadingOlder]);

  // Handle scroll: bottom detection for auto-scroll + top detection for load-older
  const handleScroll = useCallback(
    (e: React.UIEvent<HTMLDivElement>) => {
      const target = e.currentTarget;
      const isAtBottom = target.scrollHeight - target.scrollTop - target.clientHeight < 50;

      if (isAtBottom !== autoScroll) {
        setAutoScroll(isAtBottom);
      }

      // Near the top and the user is actively reading history (!autoScroll
      // guards against the initial smooth-scroll-to-bottom passing the top).
      if (
        target.scrollTop < 100 &&
        !autoScroll &&
        hasOlder &&
        !isLoadingOlder &&
        !loadOlderAnchorRef.current &&
        onLoadOlder
      ) {
        loadOlderAnchorRef.current = {
          scrollTop: target.scrollTop,
          scrollHeight: target.scrollHeight,
        };
        onLoadOlder();
      }
    },
    [autoScroll, hasOlder, isLoadingOlder, onLoadOlder]
  );

  // Handle scroll to bottom button click
  const handleScrollToBottom = useCallback(() => {
    setAutoScroll(true);
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, []);

  const createdDate = new Date(conversation.created_at);
  const isCreatedDateValid = isValid(createdDate);

  const renderMessages = () => {
    if (channelType === 'telegram') {
      return messages.map((message, index) => (
        <TelegramMessageBubble
          key={message.id}
          message={message}
          previousMessage={index > 0 ? messages[index - 1] : undefined}
        />
      ));
    }

    return messages.map((message, index) => (
      <LINEMessageBubble
        key={message.id}
        message={message}
        previousMessage={index > 0 ? messages[index - 1] : undefined}
      />
    ));
  };

  return (
    <div className="flex-1 relative min-h-0">
      <ScrollArea
        className="h-full p-4"
        viewportRef={scrollViewportRef}
        onScroll={handleScroll}
      >
        <div className="space-y-4 max-w-3xl mx-auto overflow-x-hidden">
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="size-6 animate-spin text-muted-foreground" />
            </div>
          ) : messages.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              No messages in this conversation yet
            </div>
          ) : (
            <>
              {isLoadingOlder && (
                <div className="flex items-center justify-center py-2">
                  <Loader2 className="size-4 animate-spin text-muted-foreground" />
                </div>
              )}
              {isCreatedDateValid && !hasOlder && (
                <div className="text-center text-sm text-muted-foreground py-2">
                  <span className="bg-muted px-3 py-1 rounded-full text-xs">
                    Started {format(createdDate, 'PPp', { locale: th })}
                  </span>
                </div>
              )}
              {renderMessages()}
            </>
          )}

          {/* Scroll anchor */}
          <div ref={messagesEndRef} />
        </div>
      </ScrollArea>

      {!autoScroll && (
        <Button
          variant="secondary"
          size="sm"
          className="absolute bottom-4 left-1/2 -translate-x-1/2 shadow-lg z-20"
          onClick={handleScrollToBottom}
        >
          <ChevronDown className="size-4 mr-2" />
          New messages
        </Button>
      )}
    </div>
  );
}
