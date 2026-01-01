import { useState, useEffect, useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router';
import { useQueryClient, type InfiniteData } from '@tanstack/react-query';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Loader2, MessageSquare, MessageCircle, MessagesSquare, Send } from 'lucide-react';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useInfiniteConversations, useMarkAsRead } from '@/hooks/useConversations';
import { useBotChannel } from '@/hooks/useEcho';
import { ConversationList } from '@/components/chat/ConversationList';
import { ChatWindow } from '@/components/chat/ChatWindow';
import { CustomerInfoPanel } from '@/components/chat/CustomerInfoPanel';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { Conversation, ConversationFilters, Message, PaginationMeta, ConversationStatusCounts } from '@/types/api';
import type { MessageSentEvent, ConversationUpdatedEvent } from '@/types/realtime';

// Response types for query cache updates
interface MessagesResponse {
  data: Message[];
  meta: PaginationMeta;
}

interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}

// Channel tabs configuration with icons for better UX
const channelTabs = [
  { value: 'all', label: 'ทั้งหมด', shortLabel: 'ทั้งหมด', color: '', bgActive: '', icon: MessagesSquare },
  { value: 'line', label: 'LINE', shortLabel: 'LINE', color: 'text-[#06C755]', bgActive: 'data-[state=active]:bg-[#06C755] data-[state=active]:text-white', icon: MessageCircle },
  { value: 'facebook', label: 'Facebook', shortLabel: 'FB', color: 'text-[#0084FF]', bgActive: 'data-[state=active]:bg-[#0084FF] data-[state=active]:text-white', icon: Send },
  { value: 'telegram', label: 'Telegram', shortLabel: 'TG', color: 'text-[#0088CC]', bgActive: 'data-[state=active]:bg-[#0088CC] data-[state=active]:text-white', icon: Send },
] as const;

export function ChatPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const queryClient = useQueryClient();

  // Get botId from URL
  const botIdParam = searchParams.get('botId');
  const botId = botIdParam ? parseInt(botIdParam, 10) : null;

  // Selected conversation
  const [selectedConversationId, setSelectedConversationId] = useState<number | null>(null);

  // Filters
  const [channelFilter, setChannelFilter] = useState<string>('all');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [search, setSearch] = useState('');

  // Determine if current channel is telegram for different sub-filters
  const isTelegramChannel = channelFilter === 'telegram';

  // Mobile info panel
  const [showInfoPanel, setShowInfoPanel] = useState(false);

  // Mobile chat view (master-detail navigation)
  const [showMobileChat, setShowMobileChat] = useState(false);

  // Bots query
  const { data: botsResponse, isLoading: isBotsLoading } = useBots();
  const bots = botsResponse?.data || [];

  // Memoize filters to prevent unnecessary query re-creations
  const filters = useMemo<ConversationFilters>(() => {
    const baseFilters: ConversationFilters = {
      search: search || undefined,
      sort_by: 'last_message_at',
      sort_direction: 'desc',
      per_page: 30,
    };

    // Add channel filter
    if (channelFilter !== 'all') {
      baseFilters.channel_type = channelFilter;
    }

    // For telegram: use telegram_chat_type filter (group/private)
    // For other channels: use status filter (active/handover)
    if (isTelegramChannel) {
      if (statusFilter !== 'all') {
        baseFilters.telegram_chat_type = statusFilter; // 'group' or 'private'
      }
    } else {
      if (statusFilter !== 'all') {
        baseFilters.status = statusFilter; // 'active' or 'handover'
      }
    }

    return baseFilters;
  }, [channelFilter, statusFilter, search, isTelegramChannel]);

  const {
    data: conversationsData,
    isLoading: isConversationsLoading,
    hasNextPage,
    isFetchingNextPage,
    fetchNextPage,
  } = useInfiniteConversations(botId ?? undefined, filters);

  // Memoize flattened conversations to avoid recreating array on every render
  const conversations = useMemo(
    () => conversationsData?.pages.flatMap((page) => page.data) || [],
    [conversationsData?.pages]
  );
  const statusCounts = conversationsData?.pages[0]?.meta?.status_counts;

  // Selected conversation
  const selectedConversation = conversations.find((c) => c.id === selectedConversationId);

  // Mark as read mutation
  const markAsRead = useMarkAsRead(botId ?? undefined);

  // Real-time WebSocket callbacks - optimized with surgical cache updates
  const handleRealtimeMessage = useCallback(
    (event: MessageSentEvent) => {
      // Surgically add the new message to cache instead of refetching
      const messageOptions = { order: 'asc' as const, perPage: 100 };
      queryClient.setQueryData<MessagesResponse>(
        ['conversation-messages', botId, event.conversation_id, messageOptions],
        (old) => {
          if (!old) return old;
          // Check if message already exists to prevent duplicates
          const exists = old.data.some((m) => m.id === event.id);
          if (exists) return old;

          // Append new message with all required fields
          const newMessage: Message = {
            id: event.id,
            conversation_id: event.conversation_id,
            sender: event.sender,
            content: event.content,
            type: event.type,
            media_url: event.media_url,
            media_type: event.media_type,
            media_metadata: null,
            model_used: null,
            prompt_tokens: null,
            completion_tokens: null,
            cost: null,
            external_message_id: null,
            reply_to_message_id: null,
            sentiment: null,
            intents: null,
            created_at: event.created_at,
            updated_at: event.created_at,
          };

          return {
            ...old,
            data: [...old.data, newMessage],
          };
        }
      );

      // Update conversation list with new message info (without full refetch)
      // Uses conversation data from event if available for accuracy
      queryClient.setQueryData<InfiniteData<ConversationsResponse>>(
        ['conversations-infinite', botId, filters],
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === event.conversation_id
                  ? {
                      ...conv,
                      last_message_at: event.conversation?.last_message_at ?? event.created_at,
                      message_count: event.conversation?.message_count ?? conv.message_count + 1,
                      // Increment unread if not currently viewing this conversation
                      unread_count: conv.id === selectedConversationId ? 0 : conv.unread_count + 1,
                    }
                  : conv
              ),
            })),
          };
        }
      );
    },
    [queryClient, botId, filters, selectedConversationId]
  );

  const handleConversationUpdate = useCallback(
    (event: ConversationUpdatedEvent) => {
      // Surgically update the specific conversation in cache
      queryClient.setQueryData<InfiniteData<ConversationsResponse>>(
        ['conversations-infinite', botId, filters],
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === event.id
                  ? {
                      ...conv,
                      status: event.status,
                      is_handover: event.is_handover,
                      assigned_user_id: event.assigned_user_id,
                      message_count: event.message_count,
                      last_message_at: event.last_message_at,
                    }
                  : conv
              ),
            })),
          };
        }
      );
    },
    [queryClient, botId, filters]
  );

  const handleNewConversation = useCallback(
    () => {
      // New conversation requires full refetch to get correct ordering
      queryClient.invalidateQueries({
        queryKey: ['conversations-infinite', botId],
      });
    },
    [queryClient, botId]
  );

  // Subscribe to bot channel for real-time updates
  useBotChannel(botId, {
    onMessage: handleRealtimeMessage,
    onConversationUpdate: handleConversationUpdate,
    onNewConversation: handleNewConversation,
  });

  // Handle bot selection (memoized to prevent child re-renders)
  const handleBotSelect = useCallback((value: string) => {
    setSearchParams({ botId: value });
    setSelectedConversationId(null);
    setShowMobileChat(false); // Reset to list view when changing bot
  }, [setSearchParams]);

  // Handle channel filter change - reset status filter when channel changes
  const handleChannelFilterChange = useCallback((value: string) => {
    setChannelFilter(value);
    setStatusFilter('all'); // Reset sub-filter when channel changes
    setSelectedConversationId(null);
  }, []);

  // Handle conversation selection (memoized to prevent child re-renders)
  const handleConversationSelect = useCallback((conversation: Conversation) => {
    setSelectedConversationId(conversation.id);
    setShowMobileChat(true); // Switch to chat view on mobile

    // Mark as read if has unread messages
    if (conversation.unread_count > 0) {
      markAsRead.mutate(conversation.id);
    }
  }, [markAsRead]);

  // Handle back to list (mobile) - memoized
  const handleBackToList = useCallback(() => {
    setShowMobileChat(false);
  }, []);

  // Handle show info panel (memoized to prevent ChatWindow re-renders)
  const handleShowInfo = useCallback(() => {
    setShowInfoPanel(true);
  }, []);

  // Auto-select first conversation if none selected (desktop only)
  useEffect(() => {
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    if (isDesktop && conversations.length > 0 && !selectedConversationId) {
      const firstConv = conversations[0];
      setSelectedConversationId(firstConv.id);
      if (firstConv.unread_count > 0) {
        markAsRead.mutate(firstConv.id);
      }
    }
  }, [conversations, selectedConversationId, markAsRead]);

  // No bot selected - show bot selector
  if (!botId) {
    return (
      <div className="flex h-[calc(100vh-3.5rem)] md:h-[calc(100vh-64px)] items-center justify-center">
        <div className="text-center space-y-4 max-w-md">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-muted">
            <MessageSquare className="h-8 w-8 text-muted-foreground" />
          </div>
          <h1 className="text-2xl font-bold">เลือก Bot</h1>
          <p className="text-muted-foreground">เลือก Bot เพื่อดูรายการสนทนา</p>
          {isBotsLoading ? (
            <Loader2 className="h-6 w-6 animate-spin mx-auto" />
          ) : (
            <Select onValueChange={handleBotSelect}>
              <SelectTrigger className="w-full">
                <SelectValue placeholder="เลือก Bot..." />
              </SelectTrigger>
              <SelectContent>
                {bots.map((bot) => (
                  <SelectItem key={bot.id} value={bot.id.toString()}>
                    {bot.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="-m-4 md:-m-6 flex h-[calc(100vh-3.5rem)] md:h-[calc(100vh-64px)] overflow-hidden bg-background">
      {/* Left Panel: Conversation List */}
      <div className={cn(
        'w-full md:w-80 flex-shrink-0 border-r flex flex-col',
        showMobileChat && 'hidden md:flex'
      )}>
        {/* Bot Selector */}
        <div className="p-3 border-b bg-muted/30">
          <Select value={botId.toString()} onValueChange={handleBotSelect}>
            <SelectTrigger className="w-full">
              <SelectValue placeholder="เลือก Bot" />
            </SelectTrigger>
            <SelectContent>
              {bots.map((bot) => (
                <SelectItem key={bot.id} value={bot.id.toString()}>
                  {bot.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Channel Tabs - Icon only on mobile, text on desktop */}
        <div className="p-2 border-b bg-muted/10">
          <Tabs value={channelFilter} onValueChange={handleChannelFilterChange}>
            <TabsList className="w-full grid grid-cols-4 h-11 gap-1">
              {channelTabs.map((tab) => {
                const Icon = tab.icon;
                return (
                  <TabsTrigger
                    key={tab.value}
                    value={tab.value}
                    className={cn(
                      'h-10 min-h-[40px] px-1 sm:px-3 text-xs sm:text-sm gap-1',
                      'transition-all duration-200',
                      tab.color,
                      tab.bgActive
                    )}
                    title={tab.label}
                  >
                    <Icon className="h-4 w-4 sm:h-3.5 sm:w-3.5 flex-shrink-0" />
                    <span className="hidden sm:inline">{tab.shortLabel}</span>
                  </TabsTrigger>
                );
              })}
            </TabsList>
          </Tabs>
        </div>

        {/* Conversation List */}
        <ConversationList
          conversations={conversations}
          selectedId={selectedConversationId}
          onSelect={handleConversationSelect}
          isLoading={isConversationsLoading}
          statusFilter={statusFilter}
          onStatusFilterChange={setStatusFilter}
          search={search}
          onSearchChange={setSearch}
          statusCounts={statusCounts}
          hasNextPage={hasNextPage}
          isFetchingNextPage={isFetchingNextPage}
          fetchNextPage={fetchNextPage}
          channelType={isTelegramChannel ? 'telegram' : undefined}
        />
      </div>

      {/* Center Panel: Chat Window */}
      <div className={cn(
        'flex-1 flex flex-col min-w-0',
        !showMobileChat && 'hidden md:flex'
      )}>
        {selectedConversation ? (
          <ChatWindow
            botId={botId}
            conversation={selectedConversation}
            onShowInfo={handleShowInfo}
            onBack={handleBackToList}
          />
        ) : (
          <div className="flex-1 flex items-center justify-center text-muted-foreground">
            <div className="text-center p-6 max-w-sm">
              <div className="mx-auto w-16 h-16 rounded-full bg-muted/50 flex items-center justify-center mb-4">
                <MessageSquare className="h-8 w-8 opacity-50" />
              </div>
              <h3 className="font-medium text-foreground mb-2">เลือกการสนทนา</h3>
              <p className="text-sm">เลือกการสนทนาจากรายการด้านซ้ายเพื่อเริ่มแชท</p>
            </div>
          </div>
        )}
      </div>

      {/* Right Panel: Customer Info (Desktop) */}
      <div className="w-96 flex-shrink-0 border-l hidden xl:block overflow-y-auto">
        {selectedConversation && (
          <CustomerInfoPanel
            botId={botId}
            conversation={selectedConversation}
          />
        )}
      </div>

      {/* Mobile Info Panel Sheet */}
      <Sheet open={showInfoPanel} onOpenChange={setShowInfoPanel}>
        <SheetContent className="w-full sm:max-w-md overflow-y-auto">
          {selectedConversation && (
            <CustomerInfoPanel
              botId={botId}
              conversation={selectedConversation}
            />
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}
