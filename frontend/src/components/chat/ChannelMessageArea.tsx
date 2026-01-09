/**
 * Channel-specific message area component
 * Handles Telegram and LINE message rendering
 * Extracted from ChatWindow.tsx
 */
import { useState } from 'react';
import { format } from 'date-fns';
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
}

export function ChannelMessageArea({
  messages,
  conversation,
  isLoading,
  channelType,
}: ChannelMessageAreaProps) {
  const [autoScroll, setAutoScroll] = useState(true);

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
    <div className="flex-1 relative">
      <ScrollArea className="h-full p-4">
        <div className="space-y-4 max-w-3xl mx-auto">
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : messages.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              No messages in this conversation yet
            </div>
          ) : (
            <>
              <div className="text-center text-sm text-muted-foreground py-2">
                <span className="bg-muted px-3 py-1 rounded-full text-xs">
                  Started {format(new Date(conversation.created_at), 'PPp', { locale: th })}
                </span>
              </div>
              {renderMessages()}
            </>
          )}
        </div>
      </ScrollArea>

      {!autoScroll && (
        <Button
          variant="secondary"
          size="sm"
          className="absolute bottom-4 left-1/2 -translate-x-1/2 shadow-lg z-20"
          onClick={() => setAutoScroll(true)}
        >
          <ChevronDown className="h-4 w-4 mr-2" />
          New messages
        </Button>
      )}
    </div>
  );
}
