import { useState, useRef, useEffect, useCallback, useMemo, memo } from 'react';
import { useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
  useConversationMessages,
  useSendAgentMessage,
  useToggleHandover,
  useClearContext,
} from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import {
  ArrowLeft,
  Loader2,
  Send,
  Info,
  Bot,
  User,
  Headphones,
  ChevronDown,
  RotateCcw,
  Users,
  MessageCircleWarning,
  CheckCircle2,
} from 'lucide-react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { cn } from '@/lib/utils';
import type { Conversation, Message, PaginationMeta } from '@/types/api';

// Type for conversations infinite query cache
interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta;
}
// Channel-specific components
import { TelegramMessageBubble } from '@/components/telegram/TelegramMessageBubble';
import { TelegramMessageInput } from '@/components/telegram/TelegramMessageInput';
import { LINEMessageBubble } from '@/components/line/LINEMessageBubble';
import { LINEMessageInput } from '@/components/line/LINEMessageInput';

const channelLabels: Record<string, string> = {
  line: 'LINE',
  facebook: 'Facebook',
  telegram: 'Telegram',
  demo: 'Demo',
};

const senderIcons = {
  user: User,
  bot: Bot,
  agent: Headphones,
};

const senderLabels = {
  user: 'ลูกค้า',
  bot: 'Bot',
  agent: 'แอดมิน',
};

// =====================
// Memoized Sub-Components (defined before main component for proper hoisting)
// =====================

interface MessageBubbleProps {
  message: Message;
  previousMessage?: Message;
}

// Memoized message bubble - prevents re-rendering unchanged messages
const MessageBubble = memo(function MessageBubble({ message, previousMessage }: MessageBubbleProps) {
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
                  ? 'bg-muted'
                  : 'bg-foreground'
              }
            >
              <SenderIcon
                className={cn(
                  'h-4 w-4',
                  message.sender === 'agent'
                    ? 'text-foreground'
                    : 'text-background'
                )}
              />
            </AvatarFallback>
          </Avatar>
        )}
      </div>
    </>
  );
});

// Memoized message item wrapper - prevents unnecessary re-renders
interface MemoizedMessageItemProps {
  message: Message;
  previousMessage?: Message;
  contextClearedAt: Date | null;
}

const MemoizedMessageItem = memo(function MemoizedMessageItem({
  message,
  previousMessage,
  contextClearedAt,
}: MemoizedMessageItemProps) {
  // Memoize the separator calculation
  const showContextSeparator = useMemo(() => {
    if (!contextClearedAt) return false;
    const messageTime = new Date(message.created_at);
    const previousMessageTime = previousMessage
      ? new Date(previousMessage.created_at)
      : null;
    return (
      (!previousMessageTime && messageTime >= contextClearedAt) ||
      (previousMessageTime && previousMessageTime < contextClearedAt && messageTime >= contextClearedAt)
    );
  }, [message.created_at, previousMessage?.created_at, contextClearedAt]);

  return (
    <div>
      {/* Context cleared separator */}
      {showContextSeparator && contextClearedAt && (
        <div className="flex items-center gap-3 py-3 my-2">
          <div className="flex-1 h-px bg-border" />
          <div className="flex items-center gap-2 text-xs text-muted-foreground bg-muted px-3 py-1 rounded-full border">
            <RotateCcw className="h-3 w-3" />
            <span>Bot เริ่มบริบทใหม่ - {format(contextClearedAt, 'PPp', { locale: th })}</span>
          </div>
          <div className="flex-1 h-px bg-border" />
        </div>
      )}
      <MessageBubble message={message} previousMessage={previousMessage} />
    </div>
  );
});

// =====================
// Main Component
// =====================

interface ChatWindowProps {
  botId: number;
  conversation: Conversation;
  onShowInfo: () => void;
  onBack?: () => void;
  isAutoHandover?: boolean;
}

export function ChatWindow({ botId, conversation, onShowInfo, onBack, isAutoHandover }: ChatWindowProps) {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const [autoScroll, setAutoScroll] = useState(true);
  const [messageInput, setMessageInput] = useState('');
  const [selectedMedia, setSelectedMedia] = useState<File | null>(null);

  // Channel detection
  const isTelegram = conversation.channel_type === 'telegram';
  const isLINE = conversation.channel_type === 'line';
  const isGroup = isTelegram && (
    conversation.telegram_chat_type === 'group' ||
    conversation.telegram_chat_type === 'supergroup'
  );

  // Messages query
  const { data: messagesResponse, isLoading: isLoadingMessages, isFetching: isFetchingMessages } = useConversationMessages(
    botId,
    conversation.id,
    { order: 'asc', perPage: 100 }
  );
  const messages = messagesResponse?.data || conversation.messages || [];
  // Show loading if either initial load OR fetching with no cached messages
  const showMessagesLoading = (isLoadingMessages || isFetchingMessages) && messages.length === 0;

  // Mutations
  const sendAgentMessage = useSendAgentMessage(botId);
  const toggleHandover = useToggleHandover(botId);
  const clearContext = useClearContext(botId);

  // Auto scroll to bottom when messages change
  useEffect(() => {
    if (autoScroll && messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages, autoScroll]);

  // Sync message count from API response to conversations cache
  // This fixes the bug where message_count becomes stale after switching conversations
  useEffect(() => {
    const serverTotal = messagesResponse?.meta?.total;
    if (serverTotal === undefined || serverTotal === conversation.message_count) {
      return; // No update needed
    }

    // Update message_count in conversations-infinite cache using partial key match
    queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
      { queryKey: ['conversations-infinite', botId] },
      (old) => {
        if (!old) return old;

        return {
          ...old,
          pages: old.pages.map((page) => ({
            ...page,
            data: page.data.map((conv) =>
              conv.id === conversation.id
                ? { ...conv, message_count: serverTotal }
                : conv
            ),
          })),
        };
      }
    );
  }, [messagesResponse?.meta?.total, conversation.id, conversation.message_count, botId, queryClient]);

  // Handle send message (memoized)
  const handleSendMessage = useCallback(async (e: React.FormEvent) => {
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
          title: 'บันทึกข้อความแล้ว แต่ส่งไม่สำเร็จ',
          description: result.delivery_error,
          variant: 'destructive',
        });
      }
    } catch {
      setMessageInput(content);
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถส่งข้อความได้',
        variant: 'destructive',
      });
    }
  }, [messageInput, sendAgentMessage, conversation.id, toast]);

  // Handle send telegram message with media upload (memoized)
  const handleSendTelegramMessage = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!messageInput.trim() && !selectedMedia) return;

    const content = messageInput.trim();
    const media = selectedMedia;
    setMessageInput('');
    setSelectedMedia(null);

    try {
      let mediaUrl: string | undefined;
      let mediaType: 'text' | 'image' | 'video' | 'audio' | 'file' = 'text';

      // Upload media if present
      if (media) {
        const formData = new FormData();
        formData.append('file', media);

        const { api } = await import('@/lib/api');
        const uploadResponse = await api.post<{ url: string; type: string }>(
          `/bots/${botId}/conversations/${conversation.id}/upload`,
          formData,
          { headers: { 'Content-Type': 'multipart/form-data' } }
        );

        mediaUrl = uploadResponse.data.url;
        mediaType = uploadResponse.data.type as 'image' | 'video' | 'audio' | 'file';
      }

      const result = await sendAgentMessage.mutateAsync({
        conversationId: conversation.id,
        data: {
          content: content || `[${mediaType}]`,
          type: mediaType,
          media_url: mediaUrl,
        },
      });

      if (result.delivery_error) {
        toast({
          title: 'บันทึกข้อความแล้ว แต่ส่งไม่สำเร็จ',
          description: result.delivery_error,
          variant: 'destructive',
        });
      }
    } catch {
      setMessageInput(content);
      if (media) setSelectedMedia(media);
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถส่งข้อความได้',
        variant: 'destructive',
      });
    }
  }, [messageInput, selectedMedia, sendAgentMessage, conversation.id, botId, toast]);

  // Handle send LINE message with media upload (memoized)
  const handleSendLINEMessage = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!messageInput.trim() && !selectedMedia) return;

    const content = messageInput.trim();
    const media = selectedMedia;
    setMessageInput('');
    setSelectedMedia(null);

    try {
      let mediaUrl: string | undefined;
      let mediaType: 'text' | 'image' | 'video' | 'audio' = 'text';

      // Upload media if present
      if (media) {
        const formData = new FormData();
        formData.append('file', media);

        const { api } = await import('@/lib/api');
        const uploadResponse = await api.post<{ url: string; type: string }>(
          `/bots/${botId}/conversations/${conversation.id}/upload`,
          formData,
          { headers: { 'Content-Type': 'multipart/form-data' } }
        );

        mediaUrl = uploadResponse.data.url;
        mediaType = uploadResponse.data.type as 'image' | 'video' | 'audio';
      }

      const result = await sendAgentMessage.mutateAsync({
        conversationId: conversation.id,
        data: {
          content: content || `[${mediaType}]`,
          type: mediaType,
          media_url: mediaUrl,
        },
      });

      if (result.delivery_error) {
        toast({
          title: 'บันทึกข้อความแล้ว แต่ส่งไม่สำเร็จ',
          description: result.delivery_error,
          variant: 'destructive',
        });
      }
    } catch {
      setMessageInput(content);
      if (media) setSelectedMedia(media);
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถส่งข้อความได้',
        variant: 'destructive',
      });
    }
  }, [messageInput, selectedMedia, sendAgentMessage, conversation.id, botId, toast]);

  // Handle toggle bot (memoized)
  const handleToggleBot = useCallback(async () => {
    try {
      await toggleHandover.mutateAsync({ conversationId: conversation.id });
      toast({
        title: conversation.is_handover ? 'เปิด Bot แล้ว' : 'เปิดโหมดรอตอบ',
        description: conversation.is_handover
          ? 'Bot จะตอบข้อความในการสนทนานี้'
          : 'คุณสามารถตอบข้อความได้โดยตรง Bot จะเปิดอัตโนมัติใน 30 นาที',
      });
    } catch {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถสลับโหมด Bot ได้',
        variant: 'destructive',
      });
    }
  }, [toggleHandover, conversation.id, conversation.is_handover, toast]);

  // Handle clear context (memoized)
  const handleClearContext = useCallback(async () => {
    try {
      await clearContext.mutateAsync(conversation.id);
      toast({
        title: 'Reset บริบทสำเร็จ',
        description: 'Bot จะเริ่มต้นใหม่โดยไม่อ้างอิงประวัติก่อนหน้า',
      });
    } catch {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถ reset บริบทได้',
        variant: 'destructive',
      });
    }
  }, [clearContext, conversation.id, toast]);

  // Memoize contextClearedAt to prevent recreating Date objects on each render
  const contextClearedAt = useMemo(() =>
    conversation.context_cleared_at
      ? new Date(conversation.context_cleared_at)
      : null,
    [conversation.context_cleared_at]
  );

  // Handle scroll to bottom (memoized)
  const handleScrollToBottom = useCallback(() => {
    setAutoScroll(true);
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, []);

  // Scroll area viewport ref for scroll detection
  const scrollViewportRef = useRef<HTMLDivElement>(null);

  // Handle scroll to detect when user scrolls to bottom - toggle autoScroll accordingly
  const handleScroll = useCallback((e: React.UIEvent<HTMLDivElement>) => {
    const target = e.currentTarget;
    const isAtBottom = target.scrollHeight - target.scrollTop - target.clientHeight < 50;

    if (isAtBottom && !autoScroll) {
      setAutoScroll(true);
    } else if (!isAtBottom && autoScroll) {
      setAutoScroll(false);
    }
  }, [autoScroll]);

  // Display name: group title for telegram groups, otherwise customer name
  const customerName = isGroup
    ? conversation.telegram_chat_title || 'Telegram Group'
    : conversation.customer_profile?.display_name || 'ลูกค้า';
  const customerInitial = customerName.charAt(0).toUpperCase();

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center justify-between p-2 sm:p-3 border-b bg-background">
        <div className="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
          {/* Back button - mobile only, prominent for easy navigation */}
          {onBack && (
            <Button
              variant="outline"
              size="icon"
              className="md:hidden h-10 w-10 min-h-[40px] min-w-[40px] flex-shrink-0 border-2"
              onClick={onBack}
              aria-label="กลับไปรายการสนทนา"
            >
              <ArrowLeft className="h-5 w-5" />
            </Button>
          )}
          <Avatar className={cn(
            'h-8 w-8 sm:h-10 sm:w-10 flex-shrink-0',
            isTelegram && 'bg-[#0088CC]/10'
          )}>
            <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
            <AvatarFallback className={isTelegram ? 'bg-[#0088CC]/10 text-[#0088CC]' : undefined}>
              {isGroup ? <Users className="h-5 w-5" /> : customerInitial}
            </AvatarFallback>
          </Avatar>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <h2 className="font-semibold text-sm sm:text-base truncate max-w-[120px] sm:max-w-none">{customerName}</h2>
              {isTelegram || isAutoHandover ? (
                // Human-only mode: Show status badge + unread
                <>
                  {conversation.status === 'closed' ? (
                    <Badge variant="secondary" className="text-xs flex-shrink-0 bg-slate-100 text-slate-500">
                      <CheckCircle2 className="h-3 w-3 mr-1 hidden sm:inline" />
                      จบแล้ว
                    </Badge>
                  ) : conversation.needs_response ? (
                    <Badge className="text-xs flex-shrink-0 text-white bg-orange-500">
                      <MessageCircleWarning className="h-3 w-3 mr-1 hidden sm:inline" />
                      ต้องตอบ
                    </Badge>
                  ) : null}
                  {/* Unread badge */}
                  {conversation.unread_count > 0 && (
                    <Badge className="text-xs flex-shrink-0 text-white bg-orange-500">
                      {conversation.unread_count} ใหม่
                    </Badge>
                  )}
                  {/* Group indicator for Telegram */}
                  {isTelegram && isGroup && (
                    <Badge variant="outline" className="text-xs flex-shrink-0 border-[#0088CC]/30 text-[#0088CC]">
                      <Users className="h-3 w-3 mr-1 hidden sm:inline" />
                      กลุ่ม
                    </Badge>
                  )}
                </>
              ) : (
                // Bot mode: Show bot/handover status + unread
                <>
                  {conversation.is_handover ? (
                    <Badge className="text-xs flex-shrink-0 text-white bg-orange-500">
                      <Headphones className="h-3 w-3 mr-1 hidden sm:inline" />
                      รอตอบ
                    </Badge>
                  ) : (
                    <Badge variant="secondary" className="text-xs flex-shrink-0 bg-blue-100 text-blue-700">
                      <Bot className="h-3 w-3 mr-1 hidden sm:inline" />
                      Bot
                    </Badge>
                  )}
                  {/* Unread badge */}
                  {conversation.unread_count > 0 && (
                    <Badge className="text-xs flex-shrink-0 text-white bg-orange-500">
                      {conversation.unread_count} ใหม่
                    </Badge>
                  )}
                </>
              )}
            </div>
            <p className="text-xs text-muted-foreground truncate">
              {channelLabels[conversation.channel_type]} - {conversation.message_count} ข้อความ
            </p>
          </div>
        </div>

        <div className="flex items-center gap-1 sm:gap-2 flex-shrink-0">
          {/* Bot controls - only for non-telegram channels */}
          {!isTelegram && (
            <>
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
                    เปิด Bot
                  </>
                ) : (
                  <>
                    <Headphones className="h-4 w-4 mr-1" />
                    ตอบเอง
                  </>
                )}
              </Button>

              {/* Clear Context Button */}
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button
                    variant="outline"
                    size="icon"
                    disabled={clearContext.isPending}
                    title="Reset บริบท Bot"
                  >
                    {clearContext.isPending ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <RotateCcw className="h-4 w-4" />
                    )}
                  </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>Reset บริบท Bot?</AlertDialogTitle>
                    <AlertDialogDescription>
                      Bot จะเริ่มบริบทใหม่ ไม่อ้างอิงประวัติเก่า แต่คุณยังดูประวัติย้อนหลังได้
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
                    <AlertDialogAction onClick={handleClearContext}>
                      Reset บริบท
                    </AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            </>
          )}

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
      <ScrollArea className="flex-1 p-4" viewportRef={scrollViewportRef} onScroll={handleScroll}>
        <div className="space-y-4 max-w-3xl mx-auto">
          {showMessagesLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : messages.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              ยังไม่มีข้อความในการสนทนานี้
            </div>
          ) : (
            <>
              {/* Conversation start indicator */}
              <div className="text-center text-sm text-muted-foreground py-2">
                <span className="bg-muted px-3 py-1 rounded-full text-xs">
                  Started {format(new Date(conversation.created_at), 'PPp', { locale: th })}
                </span>
              </div>

              {/* Messages - channel-specific rendering */}
              {isTelegram ? (
                // Telegram: Use TelegramMessageBubble for rich media support
                messages.map((message, index) => (
                  <TelegramMessageBubble
                    key={message.id}
                    message={message}
                    previousMessage={index > 0 ? messages[index - 1] : undefined}
                  />
                ))
              ) : isLINE ? (
                // LINE: Use LINEMessageBubble for rich media support
                messages.map((message, index) => (
                  <LINEMessageBubble
                    key={message.id}
                    message={message}
                    previousMessage={index > 0 ? messages[index - 1] : undefined}
                  />
                ))
              ) : (
                // Other channels: Use standard MessageBubble with context separator
                messages.map((message, index) => (
                  <MemoizedMessageItem
                    key={message.id}
                    message={message}
                    previousMessage={index > 0 ? messages[index - 1] : undefined}
                    contextClearedAt={contextClearedAt}
                  />
                ))
              )}

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
          onClick={handleScrollToBottom}
        >
          <ChevronDown className="h-4 w-4 mr-2" />
          ข้อความใหม่
        </Button>
      )}

      {/* Footer - Chat Input */}
      <div className="border-t bg-background">
        {conversation.status === 'closed' ? (
          <div className="p-4 text-center text-sm text-muted-foreground">
            การสนทนานี้ปิดแล้ว
          </div>
        ) : isTelegram ? (
          // Telegram: Always show input (human-only mode) with media upload
          <TelegramMessageInput
            value={messageInput}
            onChange={setMessageInput}
            selectedMedia={selectedMedia}
            onMediaSelect={setSelectedMedia}
            onSubmit={handleSendTelegramMessage}
            isLoading={sendAgentMessage.isPending}
          />
        ) : isLINE && conversation.is_handover ? (
          // LINE: Handover mode - show LINE input with media upload support
          <LINEMessageInput
            value={messageInput}
            onChange={setMessageInput}
            selectedMedia={selectedMedia}
            onMediaSelect={setSelectedMedia}
            onSubmit={handleSendLINEMessage}
            isLoading={sendAgentMessage.isPending}
          />
        ) : conversation.is_handover ? (
          // Other channels: Handover mode - show basic text input
          <form onSubmit={handleSendMessage} className="p-2 sm:p-3">
            <div className="flex gap-2 max-w-3xl mx-auto">
              <div className="flex-1 relative">
                <Input
                  value={messageInput}
                  onChange={(e) => setMessageInput(e.target.value)}
                  placeholder="พิมพ์ข้อความ..."
                  disabled={sendAgentMessage.isPending}
                  className="pr-12 min-h-[44px] text-base sm:text-sm"
                  autoFocus
                />
                <div className="absolute right-3 top-1/2 -translate-y-1/2">
                  <Headphones className="h-4 w-4 text-muted-foreground" />
                </div>
              </div>
              <Button
                type="submit"
                disabled={!messageInput.trim() || sendAgentMessage.isPending}
                className="h-11 w-11 p-0"
              >
                {sendAgentMessage.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Send className="h-4 w-4" />
                )}
              </Button>
            </div>
            <p className="text-center text-xs text-muted-foreground mt-2 hidden sm:block">
              โหมดตอบเอง - ข้อความจะส่งถึงลูกค้าโดยตรง
            </p>
          </form>
        ) : (
          // Other channels: Bot mode - show bot indicator
          <div className="p-4 text-center text-sm text-muted-foreground">
            <Bot className="h-4 w-4 inline-block mr-1" />
            Bot กำลังตอบการสนทนานี้ กด "ตอบเอง" เพื่อตอบด้วยตนเอง
          </div>
        )}
      </div>
    </div>
  );
}

