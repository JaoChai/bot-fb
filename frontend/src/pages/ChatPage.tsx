/**
 * T026: Refactored ChatPage
 * Uses chatStore for UI state and new hooks for data
 * Reduced from ~560 lines to ~200 lines
 */
import { useEffect, useCallback, useMemo, useDeferredValue } from 'react';
import { useSearchParams } from 'react-router';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Loader2, MessageSquare, RotateCcw } from 'lucide-react';
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
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
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
      <div className="flex h-[calc(100vh-3.5rem)] md:h-[calc(100vh-64px)] items-center justify-center">
        <div className="text-center space-y-4 max-w-md">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-muted">
            <MessageSquare className="h-8 w-8 text-muted-foreground" />
          </div>
          <h1 className="text-2xl font-bold">Select a Bot</h1>
          <p className="text-muted-foreground">Choose a bot to view conversations</p>
          {isBotsLoading ? (
            <Loader2 className="h-6 w-6 animate-spin mx-auto" />
          ) : (
            <Select onValueChange={handleBotSelect}>
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Select Bot..." />
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
              <SelectValue placeholder="Select Bot" />
            </SelectTrigger>
            <SelectContent>
              {bots.map((bot) => (
                <SelectItem key={bot.id} value={bot.id.toString()}>
                  {bot.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          {/* Bulk Reset Context */}
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className="w-full mt-2"
                disabled={clearContextAll.isPending || !botId}
              >
                {clearContextAll.isPending ? (
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <RotateCcw className="h-4 w-4 mr-2" />
                )}
                Reset All Contexts
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Reset all contexts?</AlertDialogTitle>
                <AlertDialogDescription>
                  Bot will start fresh with all open conversations.
                  Chat history will be preserved but bot will not reference previous messages.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction onClick={handleClearContextAll}>
                  Reset All
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>

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
              <h3 className="font-medium text-foreground mb-2">Select a conversation</h3>
              <p className="text-sm">Choose a conversation from the list to start chatting</p>
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
      <Sheet open={isCustomerPanelOpen} onOpenChange={setCustomerPanelOpen}>
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
