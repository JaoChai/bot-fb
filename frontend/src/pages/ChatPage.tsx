import { useState, useEffect, useCallback } from 'react';
import { useSearchParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Loader2, MessageSquare } from 'lucide-react';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useConversations, useMarkAsRead } from '@/hooks/useConversations';
import { useBotChannel } from '@/hooks/useEcho';
import { ConversationList } from '@/components/chat/ConversationList';
import { ChatWindow } from '@/components/chat/ChatWindow';
import { CustomerInfoPanel } from '@/components/chat/CustomerInfoPanel';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { Conversation, ConversationFilters } from '@/types/api';

export function ChatPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const queryClient = useQueryClient();

  // Get botId from URL
  const botIdParam = searchParams.get('botId');
  const botId = botIdParam ? parseInt(botIdParam, 10) : null;

  // Selected conversation
  const [selectedConversationId, setSelectedConversationId] = useState<number | null>(null);

  // Filters
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [search, setSearch] = useState('');

  // Mobile info panel
  const [showInfoPanel, setShowInfoPanel] = useState(false);

  // Mobile chat view (master-detail navigation)
  const [showMobileChat, setShowMobileChat] = useState(false);

  // Bots query
  const { data: botsResponse, isLoading: isBotsLoading } = useBots();
  const bots = botsResponse?.data || [];

  // Conversations query
  const filters: ConversationFilters = {
    status: statusFilter === 'all' ? undefined : statusFilter,
    search: search || undefined,
    sort_by: 'last_message_at',
    sort_direction: 'desc',
    per_page: 50,
  };

  const { data: conversationsResponse, isLoading: isConversationsLoading } = useConversations(
    botId ?? undefined,
    filters
  );
  const conversations = conversationsResponse?.data || [];
  const statusCounts = conversationsResponse?.meta?.status_counts;

  // Selected conversation
  const selectedConversation = conversations.find((c) => c.id === selectedConversationId);

  // Mark as read mutation
  const markAsRead = useMarkAsRead(botId ?? undefined);

  // Real-time WebSocket callbacks (memoized to prevent re-subscriptions)
  const handleRealtimeMessage = useCallback(
    (event: { conversation_id: number }) => {
      // Invalidate messages for the specific conversation
      queryClient.invalidateQueries({
        queryKey: ['conversation-messages', botId, event.conversation_id],
      });
      // Also update conversation list (for last_message_at, unread_count)
      queryClient.invalidateQueries({
        queryKey: ['conversations', botId],
      });
    },
    [queryClient, botId]
  );

  const handleConversationUpdate = useCallback(
    () => {
      // Invalidate conversation list
      queryClient.invalidateQueries({
        queryKey: ['conversations', botId],
      });
    },
    [queryClient, botId]
  );

  const handleNewConversation = useCallback(
    () => {
      // Invalidate conversation list to show new conversation
      queryClient.invalidateQueries({
        queryKey: ['conversations', botId],
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

  // Handle bot selection
  const handleBotSelect = (value: string) => {
    setSearchParams({ botId: value });
    setSelectedConversationId(null);
    setShowMobileChat(false); // Reset to list view when changing bot
  };

  // Handle conversation selection
  const handleConversationSelect = (conversation: Conversation) => {
    setSelectedConversationId(conversation.id);
    setShowMobileChat(true); // Switch to chat view on mobile

    // Mark as read if has unread messages
    if (conversation.unread_count > 0) {
      markAsRead.mutate(conversation.id);
    }
  };

  // Handle back to list (mobile)
  const handleBackToList = () => {
    setShowMobileChat(false);
  };

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
            onShowInfo={() => setShowInfoPanel(true)}
            onBack={handleBackToList}
          />
        ) : (
          <div className="flex-1 flex items-center justify-center text-muted-foreground">
            <div className="text-center">
              <MessageSquare className="h-12 w-12 mx-auto mb-4 opacity-50" />
              <p>เลือกการสนทนาเพื่อเริ่มแชท</p>
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
