/**
 * T026: Refactored ChatPage
 * Uses chatStore for UI state and new hooks for data
 * Reduced from ~560 lines to ~200 lines
 */
import { useEffect, useCallback, useMemo, useDeferredValue, useRef } from 'react';
import { useSearchParams } from 'react-router';
import { BotPicker, EmptyState } from '@/components/common';
import { AlertCircle, Loader2, MessageSquare } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useBotPreferencesStore } from '@/stores/botPreferencesStore';
import { useChatStore } from '@/stores/chatStore';
import {
  useInfiniteConversationList,
  useRealtime,
  useMarkAsRead,
  useClearContextAll,
  useLinkedConversation,
} from '@/hooks/chat';
// The strict link-parameter parser stays outside the mocked '@/hooks/chat'
// barrel so ChatPage.test.tsx's module mock does not need it (F1b).
import { parseConversationLinkParam } from '@/hooks/chat/useLinkedConversation';
import { ConversationList } from '@/components/chat/ConversationList';
import { ChatWindow } from '@/components/chat/ChatWindow';
import { CustomerInfoPanel } from '@/components/chat/CustomerInfoPanel';
import { BotSelectorPanel } from '@/components/chat/BotSelectorPanel';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { VisuallyHidden } from 'radix-ui';
import { useToast } from '@/hooks/use-toast';
import { useKeyboardInset } from '@/hooks/useKeyboardInset';
import { cn } from '@/lib/utils';
import type { Bot, Conversation, ConversationFilters } from '@/types/api';

const EMPTY_BOTS: Bot[] = [];

export function ChatPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const { toast } = useToast();

  // คีย์บอร์ดจอสัมผัสกินพื้นที่จากขอบล่าง — หักออกจากความสูงหน้าแชท
  const keyboardInset = useKeyboardInset();

  // Get botId from URL
  const botIdParam = searchParams.get('botId');
  const botId = botIdParam ? parseInt(botIdParam, 10) : null;

  // F1b deep link: /chat?botId=<b>&conversationId=<c> opens exactly that room,
  // even when it sits outside the loaded list pages. A parameter present but
  // not a positive safe integer is a bad link — no request is ever made.
  const conversationIdParam = searchParams.get('conversationId');
  const linkedBotId = parseConversationLinkParam(botIdParam);
  const linkedConversationId = parseConversationLinkParam(conversationIdParam);
  const hasConversationLink = conversationIdParam !== null;

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
  const bots = botsResponse?.data || EMPTY_BOTS;

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

  // F1b: the linked room (fetched outside the list pages; disabled unless both
  // ids are positive safe integers, so a bad link makes no request).
  const linkedRoom = useLinkedConversation(linkedBotId, linkedConversationId);

  // Selected conversation: the list copy wins when the list holds the id,
  // otherwise the deep-link copy stands in for a room outside the loaded pages.
  const selectedConversation = useMemo(() => {
    const fromList = conversations.find((c) => c.id === selectedConversationId);
    if (fromList) return fromList;
    if (hasConversationLink && selectedConversationId === linkedConversationId && linkedRoom.data) {
      return linkedRoom.data;
    }
    return undefined;
  }, [conversations, selectedConversationId, hasConversationLink, linkedConversationId, linkedRoom.data]);

  // Link state machine (D132/D134): pending shows a loader, never another
  // room; a 404/403 or a malformed link shows the not-found panel; anything
  // else failing shows the retry panel.
  const linkErrorStatus = (linkedRoom.error as { status?: number } | null)?.status;
  const linkState: 'pending' | 'ready' | 'not-found' | 'load-failed' | null = useMemo(() => {
    if (!hasConversationLink) return null;
    if (linkedBotId === null || linkedConversationId === null) return 'not-found';
    if (linkedRoom.isError) {
      return linkErrorStatus === 404 || linkErrorStatus === 403 ? 'not-found' : 'load-failed';
    }
    if (linkedRoom.data || conversations.some((c) => c.id === linkedConversationId)) return 'ready';
    return 'pending';
  }, [
    hasConversationLink,
    linkedBotId,
    linkedConversationId,
    linkedRoom.isError,
    linkedRoom.data,
    linkErrorStatus,
    conversations,
  ]);
  const isLinkPending = linkState === 'pending';
  const isLinkError = linkState === 'not-found' || linkState === 'load-failed';

  // Mutations
  const markAsRead = useMarkAsRead(botId ?? undefined);
  const clearContextAll = useClearContextAll(botId ?? undefined);

  // Real-time WebSocket updates
  useRealtime(botId, filters, { selectedConversationId });

  // Auto-redirect to last used bot or first bot
  useEffect(() => {
    // A conversationId link owns the URL while it is present — rewriting it
    // here would silently drop the parameter and break the link.
    if (botId || hasConversationLink || isBotsLoading || bots.length === 0) return;

    const lastUsedBotExists = lastUsedBotId && bots.some((b) => b.id === lastUsedBotId);
    const targetBotId = lastUsedBotExists ? lastUsedBotId : bots[0].id;

    setSearchParams({ botId: targetBotId.toString() }, { replace: true });
  }, [botId, hasConversationLink, isBotsLoading, bots, lastUsedBotId, setSearchParams]);

  // Auto-select first conversation on desktop
  useEffect(() => {
    // While a link resolves, no room may be auto-selected (rule 1: the loader
    // replaces the auto-select; the link applies its own room on success).
    if (hasConversationLink) return;
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    if (isDesktop && conversations.length > 0 && !selectedConversationId) {
      const firstConv = conversations[0];
      selectConversation(firstConv.id);
      if (firstConv.unread_count > 0) {
        markAsRead.mutate(firstConv.id);
      }
    }
  }, [hasConversationLink, conversations, selectedConversationId, selectConversation, markAsRead]);

  // ห้องแชทที่เลือกอาจหลุดจากรายการ (realtime อัพเดตแล้วตกนอกหน้าที่โหลดไว้)
  // บนมือถือสถานะนั้นคือจอเปล่าที่ไม่มีทั้ง header ของแอปและปุ่มย้อนกลับ = ออกไปไหนไม่ได้
  // จึงพากลับหน้ารายการเอง แทนที่จะปล่อยให้ค้าง
  useEffect(() => {
    if (!showMobileChat) return;
    if (isConversationsLoading) return;
    if (selectedConversation) return;
    setShowMobileChat(false);
  }, [showMobileChat, isConversationsLoading, selectedConversation, setShowMobileChat]);

  // Handle bot selection
  const handleBotSelect = useCallback((value: string) => {
    const newBotId = parseInt(value, 10);
    setSearchParams({ botId: value });
    setLastUsedBotId(newBotId);
    selectConversation(null);
    setShowMobileChat(false);
  }, [setSearchParams, setLastUsedBotId, selectConversation, setShowMobileChat]);

  // F1b: the conversationId parameter leaves the URL (replace, botId kept)
  // whenever the user leaves the linked room — another room, phone back,
  // another bot, or an error panel button.
  const clearConversationParam = useCallback(() => {
    const next = new URLSearchParams(searchParams);
    next.delete('conversationId');
    setSearchParams(next, { replace: true });
  }, [searchParams, setSearchParams]);

  const handleLinkRetry = useCallback(() => {
    void linkedRoom.refetch();
  }, [linkedRoom]);

  // Handle conversation selection
  const handleConversationSelect = useCallback((conversation: Conversation) => {
    selectConversation(conversation.id);
    if (conversation.unread_count > 0) {
      markAsRead.mutate(conversation.id);
    }
    if (hasConversationLink) clearConversationParam();
  }, [selectConversation, markAsRead, hasConversationLink, clearConversationParam]);

  // Handle back to list (mobile)
  const handleBackToList = useCallback(() => {
    setShowMobileChat(false);
    if (hasConversationLink) clearConversationParam();
  }, [setShowMobileChat, hasConversationLink, clearConversationParam]);

  // F1b: apply the link ONCE per botId+conversationId value — later list
  // refreshes and realtime updates must not select the room again. The
  // once-guard resets when the parameter leaves the URL, so opening the same
  // link again re-applies it.
  const appliedLinkRef = useRef<string | null>(null);
  useEffect(() => {
    if (!hasConversationLink) {
      appliedLinkRef.current = null;
      return;
    }
    if (linkState !== 'ready' || linkedBotId === null || linkedConversationId === null) return;
    const linkKey = `${linkedBotId}:${linkedConversationId}`;
    if (appliedLinkRef.current === linkKey) return;
    appliedLinkRef.current = linkKey;
    selectConversation(linkedConversationId);
    const room =
      conversations.find((c) => c.id === linkedConversationId) ?? linkedRoom.data;
    if (room && room.unread_count > 0) {
      markAsRead.mutate(linkedConversationId);
    }
  }, [
    hasConversationLink,
    linkState,
    linkedBotId,
    linkedConversationId,
    conversations,
    linkedRoom.data,
    selectConversation,
    markAsRead,
  ]);

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

  // F1b error panels (D134 wording). Defined before the no-bot early return so
  // a link with an unusable botId shows the same not-found panel instead of
  // the bot selector (it can never open its room; the bot redirect is blocked
  // while the parameter is in the URL).
  const linkErrorPanel = (
    <div className="flex-1 flex items-center justify-center p-6">
      <EmptyState
        icon={AlertCircle}
        size="lg"
        title={linkState === 'load-failed' ? 'โหลดห้องแชตไม่สำเร็จ' : 'เปิดห้องแชตนี้ไม่ได้'}
        description={
          linkState === 'load-failed'
            ? 'ลองอีกครั้ง หรือกลับไปเลือกห้องจากรายการ'
            : 'ห้องนี้อาจถูกลบ หรือบัญชีนี้ไม่มีสิทธิ์ดู'
        }
        action={
          linkState === 'load-failed' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={handleLinkRetry}>
                ลองอีกครั้ง
              </Button>
              <Button onClick={clearConversationParam}>กลับไปที่รายการแชต</Button>
            </div>
          ) : (
            <Button onClick={clearConversationParam}>กลับไปที่รายการแชต</Button>
          )
        }
      />
    </div>
  );

  // No bot selected - show bot selector
  if (!botId) {
    if (isLinkError) {
      return <div className="flex h-full">{linkErrorPanel}</div>;
    }
    return (
      <div className="flex h-full items-center justify-center p-6">
        <EmptyState
          icon={MessageSquare}
          title="เลือกบอท"
          description="เลือกบอทเพื่อดูการสนทนา"
          size="lg"
          action={
            isBotsLoading ? (
              <Loader2 className="size-5 animate-spin text-muted-foreground" />
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
    <div
      className="flex h-full overflow-hidden bg-background pl-[env(safe-area-inset-left,0px)] pr-[env(safe-area-inset-right,0px)]"
      style={keyboardInset > 0 ? { height: `calc(100% - ${keyboardInset}px)` } : undefined}
    >
      {/* Left Panel: Conversation List — phone: hidden when a room shows or a
          link error replaces the list; stays visible while a link is pending */}
      <div
        data-testid="chat-list-panel"
        className={cn(
          'w-full md:w-80 flex-shrink-0 border-r flex flex-col',
          !isLinkPending && (showMobileChat || isLinkError) && 'hidden md:flex'
        )}
      >
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

      {/* Center Panel: Chat Window / link loader / link error panel.
          Phone: hidden while pending or idle-without-selection; always
          visible when the link errored (the panel replaces the list). */}
      <div
        data-testid="chat-center-panel"
        className={cn(
          'flex-1 flex flex-col min-w-0 min-h-0',
          !isLinkError && (isLinkPending || !showMobileChat) && 'hidden md:flex'
        )}
      >
        {isLinkError ? (
          linkErrorPanel
        ) : isLinkPending ? (
          <div className="flex-1 flex items-center justify-center p-6">
            <EmptyState
              size="lg"
              className="border-0 bg-transparent"
              title="กำลังเปิดห้องแชต"
              action={<Loader2 className="size-5 animate-spin text-muted-foreground" />}
            />
          </div>
        ) : selectedConversation ? (
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

      {/* Right Panel: Customer Info (Desktop) — gated while a link is pending
          or errored so no room's info panel renders */}
      <div className="w-96 flex-shrink-0 border-l hidden xl:block overflow-y-auto">
        {selectedConversation && !isLinkPending && !isLinkError && (
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
            {selectedConversation && !isLinkPending && !isLinkError && (
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
