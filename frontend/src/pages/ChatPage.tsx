/**
 * T026: Refactored ChatPage
 * Uses chatStore for UI state and new hooks for data
 * Reduced from ~560 lines to ~200 lines
 */
import { useEffect, useCallback, useMemo, useDeferredValue } from 'react';
import { useSearchParams } from 'react-router';
import { BotPicker, EmptyState } from '@/components/common';
import { Loader2, MessageSquare } from 'lucide-react';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useBotPreferencesStore } from '@/stores/botPreferencesStore';
import { useChatStore } from '@/stores/chatStore';
import {
  useInfiniteConversationList,
  useRealtime,
  useMarkAsRead,
} from '@/hooks/chat';
import { useClearContextAll } from '@/hooks/useConversations';
import { ConversationList } from '@/components/chat/ConversationList';
import { ChatWindow } from '@/components/chat/ChatWindow';
import { CustomerInfoPanel } from '@/components/chat/CustomerInfoPanel';
import { BotSelectorPanel } from '@/components/chat/BotSelectorPanel';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import * as VisuallyHidden from '@radix-ui/react-visually-hidden';
import { useToast } from '@/hooks/use-toast';
import { cn } from '@/lib/utils';
import type { Conversation, ConversationFilters } from '@/types/api';

export function ChatPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const { toast } = useToast();

  // Get botId from URL
  const botIdParam = searchParams.get('botId');
  const botId = botIdParam ? parseInt(botIdParam, 10) : null;

  // UI state from chatStore
  const {
    selectedConversationId,
    selectConversation,
    isCustomerPanelOpen,
    setCustomerPanelOpen,
    showMobileChat,
    setShowMobileChat,
    searchQuery,
    setSearchQuery,
  } = useChatStore();

  // Debounce search
  const deferredSearch = useDeferredValue(searchQuery);

  // Bots query
  const { data: botsResponse, isLoading: isBotsLoading } = useBots();
  const bots = botsResponse?.data || [];

  // Bot preferences
  const { lastUsedBotId, setLastUsedBotId } = useBotPreferencesStore();

  // Filters
  const filters = useMemo<ConversationFilters>(() => ({
    search: deferredSearch || undefined,
    sort_by: 'last_message_at',
    sort_direction: 'desc',
    per_page: 30,
  }), [deferredSearch]);

  // Conversations query with infinite scroll
  const {
    data: conversationsData,
    isLoading: isConversationsLoading,
    isFetching: isConversationsFetching,
    hasNextPage,
    isFetchingNextPage,
    fetchNextPage,
  } = useInfiniteConversationList(botId ?? undefined, filters);

  // Flatten conversations
  const conversations = useMemo(() => {
    return conversationsData?.pages.flatMap((page) => page.data) || [];
  }, [conversationsData?.pages]);

  // Selected conversation
  const selectedConversation = conversations.find((c) => c.id === selectedConversationId);

  // Mutations
  const markAsRead = useMarkAsRead(botId ?? undefined);
  const clearContextAll = useClearContextAll(botId ?? undefined);

  // Real-time WebSocket updates
  useRealtime(botId, filters, { selectedConversationId });

  // Auto-redirect to last used bot or first bot
  useEffect(() => {
    if (botId || isBotsLoading || bots.length === 0) return;

    const lastUsedBotExists = lastUsedBotId && bots.some((b) => b.id === lastUsedBotId);
    const targetBotId = lastUsedBotExists ? lastUsedBotId : bots[0].id;

    setSearchParams({ botId: targetBotId.toString() }, { replace: true });
  }, [botId, isBotsLoading, bots, lastUsedBotId, setSearchParams]);

  // Auto-select first conversation on desktop
  useEffect(() => {
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    if (isDesktop && conversations.length > 0 && !selectedConversationId) {
      const firstConv = conversations[0];
      selectConversation(firstConv.id);
      if (firstConv.unread_count > 0) {
        markAsRead.mutate(firstConv.id);
      }
    }
  }, [conversations, selectedConversationId, selectConversation, markAsRead]);

  // Handle bot selection
  const handleBotSelect = useCallback((value: string) => {
    const newBotId = parseInt(value, 10);
    setSearchParams({ botId: value });
    setLastUsedBotId(newBotId);
    selectConversation(null);
    setShowMobileChat(false);
  }, [setSearchParams, setLastUsedBotId, selectConversation, setShowMobileChat]);

  // Handle conversation selection
  const handleConversationSelect = useCallback((conversation: Conversation) => {
    selectConversation(conversation.id);
    if (conversation.unread_count > 0) {
      markAsRead.mutate(conversation.id);
    }
  }, [selectConversation, markAsRead]);

  // Handle back to list (mobile)
  const handleBackToList = useCallback(() => {
    setShowMobileChat(false);
  }, [setShowMobileChat]);

  // Handle show info panel
  const handleShowInfo = useCallback(() => {
    setCustomerPanelOpen(true);
  }, [setCustomerPanelOpen]);

  // Handle clear context all
  const handleClearContextAll = useCallback(() => {
    clearContextAll.mutate(undefined, {
      onSuccess: (data) => {
        toast({
          title: 'Context reset successful',
          description: `Reset ${data.data.updated_count} conversations`,
        });
      },
      onError: () => {
        toast({
          title: 'Error',
          description: 'Failed to reset context',
          variant: 'destructive',
        });
      },
    });
  }, [clearContextAll, toast]);

  // No bot selected - show bot selector
  if (!botId) {
    return (
      <div className="flex h-[calc(100vh-3.5rem)] md:h-[calc(100vh-64px)] items-center justify-center p-6">
        <EmptyState
          icon={MessageSquare}
          title="เลือกบอท"
          description="เลือกบอทเพื่อดูการสนทนา"
          size="lg"
          action={
            isBotsLoading ? (
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            ) : (
              <div className="w-64">
                <BotPicker
                  bots={bots.map((b) => ({ id: b.id, name: b.name }))}
                  onChange={handleBotSelect}
                  placeholder="Select Bot..."
                />
              </div>
            )
          }
        />
      </div>
    );
  }

  return (
    <div className="-mx-4 -mb-4 -mt-14 md:-m-6 flex h-[calc(100%+4.5rem)] md:h-[calc(100%+3rem)] overflow-hidden bg-background">
      {/* Left Panel: Conversation List */}
      <div className={cn(
        'w-full md:w-80 flex-shrink-0 border-r flex flex-col',
        showMobileChat && 'hidden md:flex'
      )}>
        {/* Bot Selector */}
        <BotSelectorPanel
          bots={bots.map((b) => ({ id: b.id, name: b.name }))}
          botId={botId}
          onBotSelect={handleBotSelect}
          onClearContextAll={handleClearContextAll}
          isClearPending={clearContextAll.isPending}
        />

        {/* Conversation List */}
        <ConversationList
          conversations={conversations}
          selectedId={selectedConversationId}
          onSelect={handleConversationSelect}
          isLoading={isConversationsLoading || (isConversationsFetching && conversations.length === 0)}
          search={searchQuery}
          onSearchChange={setSearchQuery}
          hasNextPage={hasNextPage}
          isFetchingNextPage={isFetchingNextPage}
          fetchNextPage={fetchNextPage}
        />
      </div>

      {/* Center Panel: Chat Window */}
      <div className={cn(
        'flex-1 flex flex-col min-w-0 min-h-0',
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
          <div className="flex-1 flex items-center justify-center p-6">
            <EmptyState
              icon={MessageSquare}
              title="เลือกการสนทนา"
              description="เลือกการสนทนาจากรายการเพื่อเริ่มต้น"
              size="lg"
              className="border-0 bg-transparent"
            />
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
      <Sheet open={isCustomerPanelOpen} onOpenChange={setCustomerPanelOpen}>
        <SheetContent className="w-full sm:max-w-md flex flex-col">
          <div className="min-h-0 flex-1 overflow-y-auto">
            <VisuallyHidden.Root>
              <SheetTitle>Customer Information</SheetTitle>
              <SheetDescription>View customer details and conversation information</SheetDescription>
            </VisuallyHidden.Root>
            {selectedConversation && (
              <CustomerInfoPanel
                botId={botId}
                conversation={selectedConversation}
              />
            )}
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}
