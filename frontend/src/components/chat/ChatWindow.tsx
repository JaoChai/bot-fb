import { useState, useRef, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
  useConversationMessages,
  useSendAgentMessage,
  useToggleHandover,
} from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import {
  Loader2,
  Send,
  Info,
  Bot,
  User,
  Headphones,
  ChevronDown,
} from 'lucide-react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { cn } from '@/lib/utils';
import type { Conversation, Message } from '@/types/api';

const channelLabels: Record<string, string> = {
  line: 'LINE',
  facebook: 'Facebook',
  demo: 'Demo',
};

const senderIcons = {
  user: User,
  bot: Bot,
  agent: Headphones,
};

const senderLabels = {
  user: 'Customer',
  bot: 'Bot',
  agent: 'Agent',
};

interface ChatWindowProps {
  botId: number;
  conversation: Conversation;
  onShowInfo: () => void;
}

export function ChatWindow({ botId, conversation, onShowInfo }: ChatWindowProps) {
  const { toast } = useToast();
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const [autoScroll, setAutoScroll] = useState(true);
  const [messageInput, setMessageInput] = useState('');

  // Messages query
  const { data: messagesResponse, isLoading: isLoadingMessages } = useConversationMessages(
    botId,
    conversation.id,
    { order: 'asc', perPage: 100 }
  );
  const messages = messagesResponse?.data || conversation.messages || [];

  // Mutations
  const sendAgentMessage = useSendAgentMessage(botId);
  const toggleHandover = useToggleHandover(botId);

  // Auto scroll to bottom when messages change
  useEffect(() => {
    if (autoScroll && messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages, autoScroll]);

  // Handle send message
  const handleSendMessage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!messageInput.trim()) return;

    const content = messageInput.trim();
    setMessageInput('');

    try {
      const result = await sendAgentMessage.mutateAsync({
        conversationId: conversation.id,
        data: { content },
      });

      if (result.delivery_error) {
        toast({
          title: 'Message saved but delivery failed',
          description: result.delivery_error,
          variant: 'destructive',
        });
      }
    } catch {
      setMessageInput(content);
      toast({
        title: 'Error',
        description: 'Failed to send message.',
        variant: 'destructive',
      });
    }
  };

  // Handle toggle bot
  const handleToggleBot = async () => {
    try {
      await toggleHandover.mutateAsync({ conversationId: conversation.id });
      toast({
        title: conversation.is_handover ? 'Bot enabled' : 'Handover enabled',
        description: conversation.is_handover
          ? 'Bot will now handle this conversation.'
          : 'You can now reply directly. Bot will auto-enable in 30 minutes.',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to toggle bot mode.',
        variant: 'destructive',
      });
    }
  };

  const customerName = conversation.customer_profile?.display_name || 'Unknown Customer';
  const customerInitial = customerName.charAt(0).toUpperCase();

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center justify-between p-3 border-b bg-background">
        <div className="flex items-center gap-3">
          <Avatar className="h-10 w-10">
            <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
            <AvatarFallback>{customerInitial}</AvatarFallback>
          </Avatar>
          <div>
            <div className="flex items-center gap-2">
              <h2 className="font-semibold">{customerName}</h2>
              {conversation.is_handover ? (
                <Badge variant="outline" className="text-amber-600 border-amber-300">
                  <Headphones className="h-3 w-3 mr-1" />
                  Handover
                </Badge>
              ) : (
                <Badge variant="outline" className="text-green-600 border-green-300">
                  <Bot className="h-3 w-3 mr-1" />
                  Bot Active
                </Badge>
              )}
            </div>
            <p className="text-xs text-muted-foreground">
              {channelLabels[conversation.channel_type]} - {conversation.message_count} messages
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* Toggle Bot Button */}
          <Button
            variant={conversation.is_handover ? 'default' : 'outline'}
            size="sm"
            onClick={handleToggleBot}
            disabled={toggleHandover.isPending}
          >
            {toggleHandover.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : conversation.is_handover ? (
              <>
                <Bot className="h-4 w-4 mr-1" />
                Enable Bot
              </>
            ) : (
              <>
                <Headphones className="h-4 w-4 mr-1" />
                Take Over
              </>
            )}
          </Button>

          {/* Info Button (for tablet) */}
          <Button
            variant="outline"
            size="icon"
            className="xl:hidden"
            onClick={onShowInfo}
          >
            <Info className="h-4 w-4" />
          </Button>
        </div>
      </div>

      {/* Messages Area */}
      <ScrollArea className="flex-1 p-4">
        <div className="space-y-4 max-w-3xl mx-auto">
          {isLoadingMessages && messages.length === 0 ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : messages.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              No messages in this conversation yet.
            </div>
          ) : (
            <>
              {/* Conversation start indicator */}
              <div className="text-center text-sm text-muted-foreground py-2">
                <span className="bg-muted px-3 py-1 rounded-full text-xs">
                  Started {format(new Date(conversation.created_at), 'PPp', { locale: th })}
                </span>
              </div>

              {/* Messages */}
              {messages.map((message, index) => (
                <MessageBubble
                  key={message.id}
                  message={message}
                  previousMessage={index > 0 ? messages[index - 1] : undefined}
                />
              ))}

              {/* Scroll anchor */}
              <div ref={messagesEndRef} />
            </>
          )}
        </div>
      </ScrollArea>

      {/* Scroll to bottom button */}
      {!autoScroll && (
        <Button
          variant="secondary"
          size="sm"
          className="absolute bottom-24 left-1/2 -translate-x-1/2 shadow-lg"
          onClick={() => {
            setAutoScroll(true);
            messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
          }}
        >
          <ChevronDown className="h-4 w-4 mr-2" />
          New messages
        </Button>
      )}

      {/* Footer - Chat Input */}
      <div className="border-t bg-background">
        {conversation.status === 'closed' ? (
          <div className="p-4 text-center text-sm text-muted-foreground">
            This conversation is closed.
          </div>
        ) : conversation.is_handover ? (
          <form onSubmit={handleSendMessage} className="p-3">
            <div className="flex gap-2 max-w-3xl mx-auto">
              <div className="flex-1 relative">
                <Input
                  value={messageInput}
                  onChange={(e) => setMessageInput(e.target.value)}
                  placeholder="Type your message..."
                  disabled={sendAgentMessage.isPending}
                  className="pr-12"
                  autoFocus
                />
                <div className="absolute right-3 top-1/2 -translate-y-1/2">
                  <Headphones className="h-4 w-4 text-amber-600" />
                </div>
              </div>
              <Button
                type="submit"
                disabled={!messageInput.trim() || sendAgentMessage.isPending}
              >
                {sendAgentMessage.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Send className="h-4 w-4" />
                )}
              </Button>
            </div>
            <p className="text-center text-xs text-muted-foreground mt-2">
              Handover mode - Messages will be sent directly to customer
            </p>
          </form>
        ) : (
          <div className="p-4 text-center text-sm text-muted-foreground">
            <Bot className="h-4 w-4 inline-block mr-1" />
            Bot is handling this conversation. Click "Take Over" to reply manually.
          </div>
        )}
      </div>
    </div>
  );
}

interface MessageBubbleProps {
  message: Message;
  previousMessage?: Message;
}

function MessageBubble({ message, previousMessage }: MessageBubbleProps) {
  const isUser = message.sender === 'user';
  const SenderIcon = senderIcons[message.sender];

  // Show timestamp if more than 5 minutes since last message
  const showTimestamp =
    !previousMessage ||
    new Date(message.created_at).getTime() - new Date(previousMessage.created_at).getTime() >
      5 * 60 * 1000;

  // Show sender change indicator
  const senderChanged = previousMessage && previousMessage.sender !== message.sender;

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
            'max-w-[70%] rounded-lg px-4 py-2',
            isUser
              ? 'bg-muted text-foreground'
              : message.sender === 'agent'
              ? 'bg-amber-50 dark:bg-amber-950 text-foreground border border-amber-200 dark:border-amber-800'
              : 'bg-primary text-primary-foreground'
          )}
        >
          {/* Sender label for non-user messages */}
          {!isUser && (
            <div className="flex items-center gap-1 text-xs opacity-70 mb-1">
              <SenderIcon className="h-3 w-3" />
              <span>{senderLabels[message.sender]}</span>
            </div>
          )}

          {/* Message content */}
          <p className="whitespace-pre-wrap break-words">{message.content}</p>

          {/* AI metadata */}
          {message.model_used && (
            <div className="text-xs opacity-60 mt-1">
              {message.model_used}
              {message.prompt_tokens && message.completion_tokens && (
                <span> - {message.prompt_tokens + message.completion_tokens} tokens</span>
              )}
            </div>
          )}
        </div>

        {/* Bot/Agent avatar */}
        {!isUser && (
          <Avatar className="h-8 w-8 shrink-0">
            <AvatarFallback
              className={
                message.sender === 'agent'
                  ? 'bg-amber-100 dark:bg-amber-900'
                  : 'bg-primary'
              }
            >
              <SenderIcon
                className={cn(
                  'h-4 w-4',
                  message.sender === 'agent'
                    ? 'text-amber-700 dark:text-amber-300'
                    : 'text-primary-foreground'
                )}
              />
            </AvatarFallback>
          </Avatar>
        )}
      </div>
    </>
  );
}
