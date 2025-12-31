import { useState, useRef, useEffect, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useConversationMessages, useSendAgentMessage } from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import {
  ArrowLeft,
  Loader2,
  Info,
  Users,
  ChevronDown,
} from 'lucide-react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { TelegramMessageBubble } from './TelegramMessageBubble';
import { TelegramMessageInput } from './TelegramMessageInput';
import type { Conversation, Message } from '@/types/api';

interface TelegramChatWindowProps {
  botId: number;
  conversation: Conversation;
  onShowInfo: () => void;
  onBack?: () => void;
}

export function TelegramChatWindow({
  botId,
  conversation,
  onShowInfo,
  onBack,
}: TelegramChatWindowProps) {
  const { toast } = useToast();
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const [autoScroll, setAutoScroll] = useState(true);
  const [messageInput, setMessageInput] = useState('');
  const [selectedMedia, setSelectedMedia] = useState<File | null>(null);

  // Messages query
  const { data: messagesResponse, isLoading: isLoadingMessages } = useConversationMessages(
    botId,
    conversation.id,
    { order: 'asc', perPage: 100 }
  );
  const messages = messagesResponse?.data || conversation.messages || [];

  // Send message mutation
  const sendAgentMessage = useSendAgentMessage(botId);

  // Auto scroll to bottom when messages change
  useEffect(() => {
    if (autoScroll && messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages, autoScroll]);

  // Handle send message
  const handleSendMessage = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();

    if (!messageInput.trim() && !selectedMedia) return;

    const content = messageInput.trim();
    setMessageInput('');

    try {
      // TODO: If selectedMedia, upload first then include media_url
      // For now, just send text
      const result = await sendAgentMessage.mutateAsync({
        conversationId: conversation.id,
        data: { content },
      });

      setSelectedMedia(null);

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
  }, [messageInput, selectedMedia, sendAgentMessage, conversation.id, toast]);

  // Handle scroll to bottom
  const handleScrollToBottom = useCallback(() => {
    setAutoScroll(true);
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, []);

  // Determine display name
  const isGroup = conversation.telegram_chat_type === 'group' ||
                  conversation.telegram_chat_type === 'supergroup';
  const displayName = isGroup
    ? conversation.telegram_chat_title || 'Telegram Group'
    : conversation.customer_profile?.display_name || 'Telegram User';
  const displayInitial = displayName.charAt(0).toUpperCase();

  return (
    <div className="flex flex-col h-full">
      {/* Header - Simplified for Telegram (no bot toggle) */}
      <div className="flex items-center justify-between p-2 sm:p-3 border-b bg-background">
        <div className="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
          {/* Back button - mobile only */}
          {onBack && (
            <Button
              variant="ghost"
              size="icon"
              className="md:hidden h-9 w-9 flex-shrink-0"
              onClick={onBack}
            >
              <ArrowLeft className="h-5 w-5" />
            </Button>
          )}
          <Avatar className="h-8 w-8 sm:h-10 sm:w-10 flex-shrink-0 bg-[#0088CC]/10">
            <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
            <AvatarFallback className="bg-[#0088CC]/10 text-[#0088CC]">
              {isGroup ? <Users className="h-5 w-5" /> : displayInitial}
            </AvatarFallback>
          </Avatar>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <h2 className="font-semibold text-sm sm:text-base truncate max-w-[180px] sm:max-w-none">
                {displayName}
              </h2>
            </div>
            <p className="text-xs text-muted-foreground truncate">
              Telegram {isGroup ? 'Group' : 'Chat'} - {conversation.message_count} ข้อความ
            </p>
          </div>
        </div>

        <div className="flex items-center gap-1 sm:gap-2 flex-shrink-0">
          {/* Info Button */}
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
              ยังไม่มีข้อความในแชทนี้
            </div>
          ) : (
            <>
              {/* Conversation start indicator */}
              <div className="text-center text-sm text-muted-foreground py-2">
                <span className="bg-muted px-3 py-1 rounded-full text-xs">
                  เริ่มต้น {format(new Date(conversation.created_at), 'PPp', { locale: th })}
                </span>
              </div>

              {/* Messages */}
              {messages.map((message: Message, index: number) => (
                <TelegramMessageBubble
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
          onClick={handleScrollToBottom}
        >
          <ChevronDown className="h-4 w-4 mr-2" />
          ข้อความใหม่
        </Button>
      )}

      {/* Footer - Chat Input (always visible since it's always human mode) */}
      <div className="border-t bg-background">
        {conversation.status === 'closed' ? (
          <div className="p-4 text-center text-sm text-muted-foreground">
            การสนทนานี้ปิดแล้ว
          </div>
        ) : (
          <TelegramMessageInput
            value={messageInput}
            onChange={setMessageInput}
            selectedMedia={selectedMedia}
            onMediaSelect={setSelectedMedia}
            onSubmit={handleSendMessage}
            isLoading={sendAgentMessage.isPending}
          />
        )}
      </div>
    </div>
  );
}
