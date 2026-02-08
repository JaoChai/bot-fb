/**
 * T020: MessageBubble component
 * T051: Updated to use channel adapters for channel-specific rendering
 * Single message display with optional channel-specific content rendering
 */
import { memo, useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
  Dialog,
  DialogContent,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Bot, User, Headphones, Download, Brain, ChevronDown, ChevronRight } from 'lucide-react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { cn } from '@/lib/utils';
import type { Message } from '@/types/api';
import { useChannel } from './adapters';

const senderIcons = {
  user: User,
  bot: Bot,
  agent: Headphones,
};

const senderLabels = {
  user: 'Customer',
  bot: 'Bot',
  agent: 'Admin',
};

export interface MessageBubbleProps {
  message: Message;
  previousMessage?: Message;
  showTimestamp?: boolean;
  /** Enable channel-specific rendering via adapter */
  useChannelAdapter?: boolean;
}

function ContentWithLightbox({
  message,
  shouldUseAdapter,
  adapter,
}: {
  message: Message;
  shouldUseAdapter: boolean;
  adapter: ReturnType<typeof useChannel>['adapter'];
}) {
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);

  const content = shouldUseAdapter
    ? adapter.renderMessageContent(message, {
        onImageClick: (url: string) => setLightboxUrl(url),
      })
    : <p className="whitespace-pre-wrap break-words">{message.content}</p>;

  return (
    <>
      {content}
      {lightboxUrl && (
        <Dialog open onOpenChange={() => setLightboxUrl(null)}>
          <DialogContent className="max-w-4xl p-0 bg-black/90 border-0">
            <div className="flex items-center justify-center min-h-[50vh]">
              <img
                src={lightboxUrl}
                alt="Full size"
                className="max-w-full max-h-[80vh] object-contain"
              />
            </div>
            <div className="absolute bottom-4 right-4">
              <Button
                variant="secondary"
                size="sm"
                asChild
              >
                <a
                  href={lightboxUrl}
                  download
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <Download className="h-4 w-4 mr-2" />
                  Download
                </a>
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}
    </>
  );
}

export const MessageBubble = memo(function MessageBubble({
  message,
  previousMessage,
  showTimestamp: forceShowTimestamp,
  useChannelAdapter = false,
}: MessageBubbleProps) {
  const [reasoningOpen, setReasoningOpen] = useState(false);
  const { adapter, channelType } = useChannel();

  const isUser = message.sender === 'user';
  const SenderIcon = senderIcons[message.sender];

  // Show timestamp if more than 5 minutes since last message
  const showTimestamp =
    forceShowTimestamp ??
    (!previousMessage ||
      new Date(message.created_at).getTime() -
        new Date(previousMessage.created_at).getTime() >
        5 * 60 * 1000);

  // Show sender change indicator
  const senderChanged = previousMessage && previousMessage.sender !== message.sender;

  // Determine if we should use channel-specific rendering
  const hasMediaType = message.type && message.type !== 'text';
  const shouldUseAdapter = useChannelAdapter && channelType && (hasMediaType || message.media_url);

  return (
    <>
      {/* Timestamp separator */}
      {showTimestamp && (
        <div className="text-center text-xs text-muted-foreground py-2">
          {format(new Date(message.created_at), 'HH:mm', { locale: th })}
        </div>
      )}

      {/* Sender change indicator */}
      {senderChanged && !showTimestamp && <div className="h-2" />}

      <div className={cn('flex gap-2', isUser ? 'justify-start' : 'justify-end')}>
        {/* User avatar */}
        {isUser && (
          <Avatar className="h-8 w-8 shrink-0">
            <AvatarFallback>
              <User className="h-4 w-4" />
            </AvatarFallback>
          </Avatar>
        )}

        {/* Message bubble */}
        <div
          className={cn(
            'max-w-[85%] sm:max-w-[70%] rounded-lg px-3 sm:px-4 py-2 break-words overflow-hidden',
            isUser
              ? 'bg-muted text-foreground'
              : message.sender === 'agent'
              ? 'bg-accent text-foreground border border-dashed'
              : 'bg-foreground text-background'
          )}
        >
          {/* Sender label for non-user messages */}
          {!isUser && (
            <div className="flex items-center gap-1 text-xs opacity-70 mb-1">
              <SenderIcon className="h-3 w-3" />
              <span>{senderLabels[message.sender]}</span>
            </div>
          )}

          {/* Reasoning content (for o1, deepseek-r1 models) */}
          {message.reasoning_content && (
            <Collapsible
              open={reasoningOpen}
              onOpenChange={setReasoningOpen}
              className="mt-2 mb-2"
            >
              <CollapsibleTrigger asChild>
                <button className="flex items-center gap-1 text-xs opacity-70 hover:opacity-100 transition-opacity">
                  <Brain className="h-3 w-3" />
                  <span>Thinking</span>
                  {reasoningOpen ? (
                    <ChevronDown className="h-3 w-3" />
                  ) : (
                    <ChevronRight className="h-3 w-3" />
                  )}
                  {message.reasoning_tokens && (
                    <span className="ml-1 opacity-60">({message.reasoning_tokens} tokens)</span>
                  )}
                </button>
              </CollapsibleTrigger>
              {reasoningOpen && (
                <CollapsibleContent>
                  <div className="mt-2 p-2 rounded bg-black/10 dark:bg-white/10 text-xs whitespace-pre-wrap max-h-48 overflow-y-auto">
                    {message.reasoning_content}
                  </div>
                </CollapsibleContent>
              )}
            </Collapsible>
          )}

          {/* Message content - uses adapter when enabled */}
          <ContentWithLightbox
            message={message}
            shouldUseAdapter={!!shouldUseAdapter}
            adapter={adapter}
          />

          {/* AI metadata */}
          {message.model_used && (
            <div className="text-xs opacity-60 mt-1">
              {message.model_used}
              {message.prompt_tokens && message.completion_tokens && (
                <span> - {message.prompt_tokens + message.completion_tokens} tokens</span>
              )}
              {message.cached_tokens && message.cached_tokens > 0 && (
                <span className="ml-1 text-green-600 dark:text-green-400">
                  ({message.cached_tokens} cached)
                </span>
              )}
            </div>
          )}
        </div>

        {/* Bot/Agent avatar */}
        {!isUser && (
          <Avatar className="h-8 w-8 shrink-0">
            <AvatarFallback
              className={message.sender === 'agent' ? 'bg-muted' : 'bg-foreground'}
            >
              <SenderIcon
                className={cn(
                  'h-4 w-4',
                  message.sender === 'agent' ? 'text-foreground' : 'text-background'
                )}
              />
            </AvatarFallback>
          </Avatar>
        )}
      </div>

    </>
  );
});

export default MessageBubble;
