import { useEffect, useRef, memo, useCallback } from 'react';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Skeleton } from '@/components/ui/skeleton';
import { Search, Loader2, MessageCircle, Bot, Headphones, Users, User } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';
import { cn } from '@/lib/utils';
import type { Conversation, ConversationStatusCounts } from '@/types/api';

const channelColors: Record<string, string> = {
  line: 'text-[#06C755]',
  facebook: 'text-[#0084FF]',
  telegram: 'text-[#0088CC]',
  demo: 'text-destructive',
};

interface ConversationListProps {
  conversations: Conversation[];
  selectedId: number | null;
  onSelect: (conversation: Conversation) => void;
  isLoading: boolean;
  statusFilter: string;
  onStatusFilterChange: (status: string) => void;
  search: string;
  onSearchChange: (search: string) => void;
  statusCounts?: ConversationStatusCounts;
  // Infinite scroll props
  hasNextPage?: boolean;
  isFetchingNextPage?: boolean;
  fetchNextPage?: () => void;
  // Channel-specific mode
  channelType?: string;
}

export function ConversationList({
  conversations,
  selectedId,
  onSelect,
  isLoading,
  statusFilter,
  onStatusFilterChange,
  search,
  onSearchChange,
  statusCounts,
  hasNextPage,
  isFetchingNextPage,
  fetchNextPage,
  channelType,
}: ConversationListProps) {
  const loadMoreRef = useRef<HTMLDivElement>(null);
  const isTelegram = channelType === 'telegram';

  // Infinite scroll using IntersectionObserver
  useEffect(() => {
    if (!loadMoreRef.current || !hasNextPage || !fetchNextPage) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting && !isFetchingNextPage) {
          fetchNextPage();
        }
      },
      { threshold: 0.1 }
    );

    observer.observe(loadMoreRef.current);
    return () => observer.disconnect();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  return (
    <div className="flex-1 flex flex-col min-h-0">
      {/* Search */}
      <div className="p-2 sm:p-3 border-b">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder={isTelegram ? "ค้นหาแชท..." : "ค้นหา..."}
            value={search}
            onChange={(e) => onSearchChange(e.target.value)}
            className="pl-9 min-h-[44px] text-base sm:text-sm"
          />
        </div>
      </div>

      {/* Status/Type Tabs - Icon only on mobile, text on desktop */}
      <div className="p-2 border-b">
        <Tabs value={statusFilter} onValueChange={onStatusFilterChange}>
          <TabsList className="w-full grid grid-cols-3 h-11 gap-1">
            <TabsTrigger value="all" className="text-xs sm:text-sm h-10 px-1 sm:px-3 gap-1" title="ทั้งหมด">
              <MessageCircle className="h-4 w-4 flex-shrink-0" />
              <span className="hidden sm:inline">ทั้งหมด</span>
              {statusCounts && (
                <Badge variant="secondary" className="text-xs px-1.5 py-0 h-5 hidden sm:inline-flex">
                  {statusCounts.total}
                </Badge>
              )}
            </TabsTrigger>
            {isTelegram ? (
              <>
                <TabsTrigger value="group" className="text-xs sm:text-sm h-10 px-1 sm:px-3 gap-1" title="กลุ่ม">
                  <Users className="h-4 w-4 flex-shrink-0" />
                  <span className="hidden sm:inline">กลุ่ม</span>
                </TabsTrigger>
                <TabsTrigger value="private" className="text-xs sm:text-sm h-10 px-1 sm:px-3 gap-1" title="ส่วนตัว">
                  <User className="h-4 w-4 flex-shrink-0" />
                  <span className="hidden sm:inline">ส่วนตัว</span>
                </TabsTrigger>
              </>
            ) : (
              <>
                <TabsTrigger value="active" className="text-xs sm:text-sm h-10 px-1 sm:px-3 gap-1" title="ใช้งาน (Bot)">
                  <Bot className="h-4 w-4 flex-shrink-0" />
                  <span className="hidden sm:inline">Bot</span>
                  {statusCounts && (
                    <Badge variant="secondary" className="text-xs px-1.5 py-0 h-5 hidden sm:inline-flex">
                      {statusCounts.active}
                    </Badge>
                  )}
                </TabsTrigger>
                <TabsTrigger value="handover" className="text-xs sm:text-sm h-10 px-1 sm:px-3 gap-1" title="รอตอบ">
                  <Headphones className="h-4 w-4 flex-shrink-0" />
                  <span className="hidden sm:inline">รอตอบ</span>
                  {statusCounts && statusCounts.handover > 0 && (
                    <Badge className="text-xs px-1.5 py-0 h-5 bg-destructive text-destructive-foreground">
                      {statusCounts.handover}
                    </Badge>
                  )}
                </TabsTrigger>
              </>
            )}
          </TabsList>
        </Tabs>
      </div>

      {/* Conversation List */}
      <ScrollArea className="flex-1">
        {isLoading ? (
          // Skeleton loading - better perceived performance
          <div className="p-2 space-y-1">
            {Array.from({ length: 6 }).map((_, i) => (
              <ConversationSkeleton key={i} />
            ))}
          </div>
        ) : conversations.length === 0 ? (
          <div className="text-center py-8 text-muted-foreground text-sm">
            ไม่พบการสนทนา
          </div>
        ) : (
          <div className="p-2 space-y-1">
            {conversations.map((conversation) => (
              <ConversationItem
                key={conversation.id}
                conversation={conversation}
                isSelected={conversation.id === selectedId}
                onSelect={onSelect}
              />
            ))}

            {/* Load more trigger */}
            <div ref={loadMoreRef} className="h-1" />

            {/* Loading indicator */}
            {isFetchingNextPage && (
              <div className="flex items-center justify-center py-4">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                <span className="ml-2 text-sm text-muted-foreground">กำลังโหลด...</span>
              </div>
            )}
          </div>
        )}
      </ScrollArea>
    </div>
  );
}

interface ConversationItemProps {
  conversation: Conversation;
  isSelected: boolean;
  onSelect: (conversation: Conversation) => void;
}

// Skeleton loading component for conversation list
function ConversationSkeleton() {
  return (
    <div className="w-full p-3 rounded-lg flex items-start gap-3 min-h-[72px]">
      <Skeleton className="h-12 w-12 md:h-10 md:w-10 rounded-full flex-shrink-0" />
      <div className="flex-1 min-w-0 space-y-2">
        <div className="flex items-center justify-between gap-2">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-3 w-10" />
        </div>
        <Skeleton className="h-3 w-16" />
        <Skeleton className="h-5 w-20" />
      </div>
    </div>
  );
}

// Memoized conversation item - handles all channel types
const ConversationItem = memo(function ConversationItem({
  conversation,
  isSelected,
  onSelect,
}: ConversationItemProps) {
  const isTelegram = conversation.channel_type === 'telegram';
  const isGroup = isTelegram && (
    conversation.telegram_chat_type === 'group' ||
    conversation.telegram_chat_type === 'supergroup'
  );

  // Display name: group title for telegram groups, otherwise customer name
  const customerName = isGroup
    ? conversation.telegram_chat_title || 'Telegram Group'
    : conversation.customer_profile?.display_name || 'Unknown';

  const customerInitial = customerName.charAt(0).toUpperCase();
  const hasUnread = conversation.unread_count > 0;
  const lastMessageTime = conversation.last_message_at
    ? formatDistanceToNow(new Date(conversation.last_message_at), { addSuffix: false, locale: th })
    : null;

  const channelColor = channelColors[conversation.channel_type] || 'text-muted-foreground';

  // Memoize click handler to prevent re-creation
  const handleClick = useCallback(() => {
    onSelect(conversation);
  }, [onSelect, conversation]);

  return (
    <button
      onClick={handleClick}
      className={cn(
        'w-full p-3 rounded-lg flex items-start gap-3 text-left transition-colors cursor-pointer',
        'hover:bg-accent/50 active:bg-accent',
        'min-h-[72px]',
        isSelected && 'bg-accent'
      )}
    >
      {/* Avatar with unread indicator */}
      <div className="relative">
        <Avatar className={cn(
          'h-12 w-12 md:h-10 md:w-10',
          isTelegram && 'bg-[#0088CC]/10'
        )}>
          <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
          <AvatarFallback className={isTelegram ? 'bg-[#0088CC]/10 text-[#0088CC]' : undefined}>
            {isGroup ? <Users className="h-5 w-5" /> : customerInitial}
          </AvatarFallback>
        </Avatar>
        {hasUnread && (
          <span className={cn(
            'absolute -top-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-background',
            isTelegram ? 'bg-[#0088CC]' : 'bg-green-500'
          )} />
        )}
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center justify-between gap-2">
          <span className={cn('font-medium truncate', hasUnread && 'font-semibold')}>
            {customerName}
          </span>
          <span className="text-xs text-muted-foreground flex-shrink-0">
            {lastMessageTime}
          </span>
        </div>

        <div className="flex items-center gap-1.5 mt-0.5">
          <MessageCircle className={cn('h-3 w-3 flex-shrink-0', channelColor)} />
          <span className="text-xs text-muted-foreground truncate">
            {conversation.message_count} ข้อความ
          </span>
        </div>

        {/* Status/Type badge - different for telegram vs other channels */}
        <div className="flex items-center gap-1.5 mt-1">
          {isTelegram ? (
            // Telegram: Show group/private badge
            isGroup ? (
              <Badge variant="outline" className="text-xs h-5 gap-1 border-[#0088CC]/30 text-[#0088CC]">
                <Users className="h-3 w-3" />
                กลุ่ม
              </Badge>
            ) : (
              <Badge variant="outline" className="text-xs h-5 gap-1 border-[#0088CC]/30 text-[#0088CC]">
                <User className="h-3 w-3" />
                ส่วนตัว
              </Badge>
            )
          ) : (
            // Other channels: Show bot/handover status
            conversation.is_handover ? (
              <Badge variant="outline" className="text-xs h-5 gap-1 border-dashed">
                <Headphones className="h-3 w-3" />
                รอตอบ
              </Badge>
            ) : (
              <Badge variant="secondary" className="text-xs h-5 gap-1">
                <Bot className="h-3 w-3" />
                Bot เปิด
              </Badge>
            )
          )}
          {hasUnread && (
            <Badge className={cn(
              'text-xs h-5',
              isTelegram ? 'bg-[#0088CC] hover:bg-[#0088CC]' : 'bg-green-500 hover:bg-green-500'
            )}>
              {conversation.unread_count} ใหม่
            </Badge>
          )}
        </div>
      </div>
    </button>
  );
});
