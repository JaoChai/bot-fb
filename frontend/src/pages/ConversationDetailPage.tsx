import { useState, useRef, useEffect } from 'react';
import { useParams, useSearchParams, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import {
  useConversation,
  useConversationMessages,
  useCloseConversation,
  useReopenConversation,
  useToggleHandover,
  useSendAgentMessage,
} from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import { NotesPanel } from '@/components/conversation/NotesPanel';
import { TagsPanel } from '@/components/conversation/TagsPanel';
import { Input } from '@/components/ui/input';
import {
  Loader2,
  ArrowLeft,
  MoreVertical,
  X,
  RotateCcw,
  UserCheck,
  Bot,
  User,
  Headphones,
  ChevronDown,
  Info,
  Mail,
  Phone,
  Calendar,
  Hash,
  Clock,
  MessagesSquare,
  Send,
} from 'lucide-react';
import type { Message, Conversation } from '@/types/api';
import { format, formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';
import { cn } from '@/lib/utils';

const statusVariants: Record<string, 'success' | 'default' | 'warning' | 'inactive'> = {
  active: 'success',
  closed: 'inactive',
  handover: 'warning',
};

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

export function ConversationDetailPage() {
  const { conversationId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  const botIdParam = searchParams.get('botId');
  const botId = botIdParam ? parseInt(botIdParam, 10) : undefined;
  const convId = conversationId ? parseInt(conversationId, 10) : undefined;

  // Refs for scroll
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const [autoScroll, setAutoScroll] = useState(true);

  // State for agent message input
  const [messageInput, setMessageInput] = useState('');

  // Queries
  const { data: conversation, isLoading, error } = useConversation(botId, convId);
  const { data: messagesResponse, isLoading: isLoadingMessages } = useConversationMessages(
    botId,
    convId,
    { order: 'asc', perPage: 100 }
  );

  const messages = messagesResponse?.data || conversation?.messages || [];

  // Mutations
  const closeConversation = useCloseConversation(botId);
  const reopenConversation = useReopenConversation(botId);
  const toggleHandover = useToggleHandover(botId);
  const sendAgentMessage = useSendAgentMessage(botId);

  // Auto scroll to bottom when messages change
  useEffect(() => {
    if (autoScroll && messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages, autoScroll]);

  // Handlers
  const handleClose = async () => {
    if (!convId) return;
    try {
      await closeConversation.mutateAsync(convId);
      toast({
        title: 'Conversation closed',
        description: 'The conversation has been closed successfully.',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to close conversation.',
        variant: 'destructive',
      });
    }
  };

  const handleReopen = async () => {
    if (!convId) return;
    try {
      await reopenConversation.mutateAsync(convId);
      toast({
        title: 'Conversation reopened',
        description: 'The conversation has been reopened.',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to reopen conversation.',
        variant: 'destructive',
      });
    }
  };

  const handleToggleHandover = async () => {
    if (!convId) return;
    try {
      await toggleHandover.mutateAsync({ conversationId: convId });
      toast({
        title: conversation?.is_handover ? 'Bot mode enabled' : 'Handover enabled',
        description: conversation?.is_handover
          ? 'The bot is now handling this conversation.'
          : 'You are now handling this conversation.',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to toggle handover mode.',
        variant: 'destructive',
      });
    }
  };

  const handleSendMessage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!convId || !messageInput.trim()) return;

    const content = messageInput.trim();
    setMessageInput(''); // Clear immediately for responsiveness

    try {
      const result = await sendAgentMessage.mutateAsync({
        conversationId: convId,
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
      setMessageInput(content); // Restore on error
      toast({
        title: 'Error',
        description: 'Failed to send message.',
        variant: 'destructive',
      });
    }
  };

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-[calc(100vh-200px)]">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  // Error state
  if (error || !conversation) {
    return (
      <div className="flex flex-col items-center justify-center h-[calc(100vh-200px)] gap-4">
        <p className="text-destructive">
          {error ? 'Error loading conversation' : 'Conversation not found'}
        </p>
        <Button variant="outline" onClick={() => navigate(-1)}>
          <ArrowLeft className="h-4 w-4 mr-2" />
          Go Back
        </Button>
      </div>
    );
  }

  const customerName = conversation.customer_profile?.display_name || 'Unknown Customer';
  const customerInitial = customerName.charAt(0).toUpperCase();

  return (
    <div className="h-[calc(100vh-100px)] flex flex-col">
      {/* Header */}
      <div className="flex items-center justify-between p-4 border-b bg-background">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate(`/chat?botId=${botId}`)}>
            <ArrowLeft className="h-4 w-4" />
          </Button>

          <Avatar className="h-10 w-10">
            <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
            <AvatarFallback>{customerInitial}</AvatarFallback>
          </Avatar>

          <div>
            <div className="flex items-center gap-2">
              <h1 className="font-semibold">{customerName}</h1>
              <Badge
                variant={statusVariants[conversation.status] || 'default'}
                className="text-xs capitalize"
              >
                {conversation.status}
              </Badge>
              {conversation.is_handover && (
                <Badge variant="outline" className="text-xs">
                  <UserCheck className="h-3 w-3 mr-1" />
                  Handover
                </Badge>
              )}
            </div>
            <p className="text-sm text-muted-foreground">
              {channelLabels[conversation.channel_type]} • {conversation.message_count} messages
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* Customer Info Sheet */}
          <Sheet>
            <SheetTrigger asChild>
              <Button variant="outline" size="sm">
                <Info className="h-4 w-4 mr-2" />
                Customer Info
              </Button>
            </SheetTrigger>
            <SheetContent className="overflow-y-auto">
              <SheetHeader>
                <SheetTitle>Customer Information</SheetTitle>
                <SheetDescription>Details about this customer</SheetDescription>
              </SheetHeader>
              <CustomerInfoPanel botId={botId!} conversation={conversation} />
            </SheetContent>
          </Sheet>

          {/* Actions Dropdown */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="icon">
                <MoreVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onClick={handleToggleHandover}
                disabled={toggleHandover.isPending}
              >
                {conversation.is_handover ? (
                  <>
                    <Bot className="h-4 w-4 mr-2" />
                    Enable Bot
                  </>
                ) : (
                  <>
                    <UserCheck className="h-4 w-4 mr-2" />
                    Take Over (Handover)
                  </>
                )}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              {conversation.status !== 'closed' ? (
                <DropdownMenuItem
                  onClick={handleClose}
                  disabled={closeConversation.isPending}
                >
                  <X className="h-4 w-4 mr-2" />
                  Close Conversation
                </DropdownMenuItem>
              ) : (
                <DropdownMenuItem
                  onClick={handleReopen}
                  disabled={reopenConversation.isPending}
                >
                  <RotateCcw className="h-4 w-4 mr-2" />
                  Reopen Conversation
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
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
                <span className="bg-muted px-3 py-1 rounded-full">
                  Conversation started {format(new Date(conversation.created_at), 'PPp', { locale: th })}
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

      {/* Footer - Chat Input or Status */}
      <div className="border-t bg-background">
        {conversation.status === 'closed' ? (
          <div className="p-4 text-center text-sm text-muted-foreground">
            <p>This conversation is closed. Reopen it to continue.</p>
          </div>
        ) : conversation.is_handover ? (
          <form onSubmit={handleSendMessage} className="p-4">
            <div className="max-w-3xl mx-auto flex gap-2">
              <div className="flex-1 relative">
                <Input
                  value={messageInput}
                  onChange={(e) => setMessageInput(e.target.value)}
                  placeholder="Type your message..."
                  disabled={sendAgentMessage.isPending}
                  className="pr-12"
                  autoFocus
                />
                <div className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
                  <Headphones className="h-4 w-4 inline-block text-amber-600 dark:text-amber-400" />
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
                <span className="ml-2 hidden sm:inline">Send</span>
              </Button>
            </div>
            <p className="text-center text-xs text-muted-foreground mt-2">
              <UserCheck className="h-3 w-3 inline-block mr-1" />
              Handover mode - Messages will be sent directly to customer
            </p>
          </form>
        ) : (
          <div className="p-4 text-center text-sm text-muted-foreground">
            <p>
              <Bot className="h-4 w-4 inline-block mr-1" />
              Bot is handling this conversation
            </p>
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
      {senderChanged && !showTimestamp && (
        <div className="h-2" />
      )}

      <div
        className={cn(
          'flex gap-2',
          isUser ? 'justify-start' : 'justify-end'
        )}
      >
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

          {/* Media attachment */}
          {message.media_url && (
            <div className="mt-2">
              {message.type === 'image' ? (
                <img
                  src={message.media_url}
                  alt="Attached image"
                  className="max-w-full rounded-md"
                />
              ) : (
                <a
                  href={message.media_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-sm underline"
                >
                  View attachment ({message.type})
                </a>
              )}
            </div>
          )}

          {/* AI metadata */}
          {message.model_used && (
            <div className="text-xs opacity-60 mt-1">
              {message.model_used}
              {message.prompt_tokens && message.completion_tokens && (
                <span> • {message.prompt_tokens + message.completion_tokens} tokens</span>
              )}
            </div>
          )}
        </div>

        {/* Bot/Agent avatar */}
        {!isUser && (
          <Avatar className="h-8 w-8 shrink-0">
            <AvatarFallback className={message.sender === 'agent' ? 'bg-amber-100 dark:bg-amber-900' : 'bg-primary'}>
              <SenderIcon className={cn('h-4 w-4', message.sender === 'agent' ? 'text-amber-700 dark:text-amber-300' : 'text-primary-foreground')} />
            </AvatarFallback>
          </Avatar>
        )}
      </div>
    </>
  );
}

interface CustomerInfoPanelProps {
  botId: number;
  conversation: Conversation;
}

function CustomerInfoPanel({ botId, conversation }: CustomerInfoPanelProps) {
  const customer = conversation.customer_profile;

  return (
    <div className="mt-6 space-y-6">
      {/* Customer Profile */}
      <div className="flex items-center gap-4">
        <Avatar className="h-16 w-16">
          <AvatarImage src={customer?.picture_url || undefined} />
          <AvatarFallback className="text-lg">
            {customer?.display_name?.charAt(0).toUpperCase() || '?'}
          </AvatarFallback>
        </Avatar>
        <div>
          <h3 className="font-semibold text-lg">
            {customer?.display_name || 'Unknown Customer'}
          </h3>
          <p className="text-sm text-muted-foreground">
            {channelLabels[conversation.channel_type]}
          </p>
        </div>
      </div>

      <Separator />

      {/* Contact Info */}
      <div className="space-y-3">
        <h4 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
          Contact Information
        </h4>

        {customer?.email && (
          <div className="flex items-center gap-2 text-sm">
            <Mail className="h-4 w-4 text-muted-foreground" />
            <span>{customer.email}</span>
          </div>
        )}

        {customer?.phone && (
          <div className="flex items-center gap-2 text-sm">
            <Phone className="h-4 w-4 text-muted-foreground" />
            <span>{customer.phone}</span>
          </div>
        )}

        <div className="flex items-center gap-2 text-sm">
          <Hash className="h-4 w-4 text-muted-foreground" />
          <span className="font-mono text-xs">{conversation.external_customer_id}</span>
        </div>
      </div>

      <Separator />

      {/* Interaction Stats */}
      <div className="space-y-3">
        <h4 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
          Interaction Stats
        </h4>

        <div className="grid grid-cols-2 gap-3">
          <Card>
            <CardContent className="p-3">
              <div className="flex items-center gap-2">
                <MessagesSquare className="h-4 w-4 text-muted-foreground" />
                <div>
                  <p className="text-2xl font-bold">{conversation.message_count}</p>
                  <p className="text-xs text-muted-foreground">Messages</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-3">
              <div className="flex items-center gap-2">
                <Hash className="h-4 w-4 text-muted-foreground" />
                <div>
                  <p className="text-2xl font-bold">{customer?.interaction_count || 1}</p>
                  <p className="text-xs text-muted-foreground">Interactions</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="flex items-center gap-2 text-sm">
          <Calendar className="h-4 w-4 text-muted-foreground" />
          <span>First seen: {customer?.first_interaction_at
            ? formatDistanceToNow(new Date(customer.first_interaction_at), { addSuffix: true, locale: th })
            : 'N/A'
          }</span>
        </div>

        <div className="flex items-center gap-2 text-sm">
          <Clock className="h-4 w-4 text-muted-foreground" />
          <span>Last message: {conversation.last_message_at
            ? formatDistanceToNow(new Date(conversation.last_message_at), { addSuffix: true, locale: th })
            : 'N/A'
          }</span>
        </div>
      </div>

      <Separator />

      {/* Tags Panel */}
      <TagsPanel
        botId={botId}
        conversationId={conversation.id}
        currentTags={conversation.tags || []}
      />

      <Separator />

      {/* Notes Panel */}
      <NotesPanel
        botId={botId}
        conversationId={conversation.id}
      />
    </div>
  );
}
