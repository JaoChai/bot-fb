import { useEffect, useRef, memo, useCallback } from 'react';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Skeleton } from '@/components/ui/skeleton';
import { Search, Loader2, MessageCircle, Users } from 'lucide-react';
import { cn } from '@/lib/utils';

// Short time format for Thai (e.g., "16น." instead of "16 นาที")
function formatTimeShort(date: Date): string {
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMins < 1) return 'เมื่อกี้';
  if (diffMins < 60) return `${diffMins}น.`;
  if (diffHours < 24) return `${diffHours}ชม.`;
  if (diffDays < 7) return `${diffDays}ว.`;
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

import type { Conversation } from '@/types/api';

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
  search: string;
  onSearchChange: (search: string) => void;
  // Infinite scroll props
  hasNextPage?: boolean;
  isFetchingNextPage?: boolean;
  fetchNextPage?: () => void;
}

export function ConversationList({
  conversations,
  selectedId,
  onSelect,
  isLoading,
  search,
  onSearchChange,
  hasNextPage,
  isFetchingNextPage,
  fetchNextPage,
}: ConversationListProps) {
  const loadMoreRef = useRef<HTMLDivElement>(null);
  // Derive isTelegram from conversations (all from same bot)
  const isTelegram = conversations[0]?.channel_type === 'telegram';

  // Infinite scroll using IntersectionObserver with debounce protection
  useEffect(() => {
    if (!loadMoreRef.current || !hasNextPage || !fetchNextPage) return;

    let isLoading = false;

    const observer = new IntersectionObserver(
      (entries) => {
        // Debounce protection: prevent multiple triggers
        if (entries[0].isIntersecting && !isFetchingNextPage && !isLoading) {
          isLoading = true;
          fetchNextPage();
          // Reset loading flag after a short delay to prevent rapid re-triggers
          setTimeout(() => {
            isLoading = false;
          }, 500);
        }
      },
      { threshold: 0.5, rootMargin: '100px' } // Higher threshold + rootMargin for earlier loading
    );

    observer.observe(loadMoreRef.current);
    return () => observer.disconnect();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  return (
    <div className="flex-1 flex flex-col min-h-0 overflow-hidden">
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

      {/* Conversation List - [&>div>div]:!block fixes Radix ScrollArea display:table issue */}
      <ScrollArea className="flex-1 w-full [&>div>div]:!block">
        {isLoading ? (
          // Skeleton loading - better perceived performance
          <div className="p-2 space-y-1 w-full">
            {Array.from({ length: 6 }).map((_, i) => (
              <ConversationSkeleton key={i} />
            ))}
          </div>
        ) : conversations.length === 0 ? (
          <div className="text-center py-8 text-muted-foreground text-sm w-full">
            ไม่พบการสนทนา
          </div>
        ) : (
          <div className="p-2 space-y-1 w-full">
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
        <Skeleton className="h-3 w-32" />
      </div>
    </div>
  );
}

// Memoized conversation item - LINE OA style with green dot
const ConversationItem = memo(function ConversationItem({
  conversation,
  isSelected,
  onSelect,
}: ConversationItemProps) {
  const isTelegram = conversation.channel_type === 'telegram';
  const isClosed = conversation.status === 'closed';
  const hasUnread = conversation.unread_count > 0;
  const isGroup = isTelegram && (
    conversation.telegram_chat_type === 'group' ||
    conversation.telegram_chat_type === 'supergroup'
  );

  // Display name: group title for telegram groups, otherwise customer name
  const customerName = isGroup
    ? conversation.telegram_chat_title || 'Telegram Group'
    : conversation.customer_profile?.display_name || 'Unknown';

  const customerInitial = customerName.charAt(0).toUpperCase();

  // Format time in short form (e.g., "16น." instead of "16 นาที")
  const lastMessageTime = conversation.last_message_at
    ? formatTimeShort(new Date(conversation.last_message_at))
    : null;

  // Last message preview - CSS handles truncation
  const lastMessagePreview = conversation.last_message?.content || null;

  const channelColor = channelColors[conversation.channel_type] || 'text-muted-foreground';

  // Memoize click handler to prevent re-creation
  const handleClick = useCallback(() => {
    onSelect(conversation);
  }, [onSelect, conversation]);

  // Simple row styling - no orange highlighting
  const rowClassName = cn(
    'w-full p-3 rounded-lg flex items-start gap-3 text-left transition-colors cursor-pointer',
    'min-h-[72px]',
    isSelected && 'bg-accent',
    isClosed && 'opacity-60',
    !isSelected && 'hover:bg-accent/50 active:bg-accent'
  );

  return (
    <button
      onClick={handleClick}
      className={rowClassName}
    >
      {/* Avatar */}
      <Avatar className={cn(
        'h-12 w-12 md:h-10 md:w-10 flex-shrink-0',
        isTelegram && 'bg-[#0088CC]/10'
      )}>
        <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
        <AvatarFallback className={isTelegram ? 'bg-[#0088CC]/10 text-[#0088CC]' : undefined}>
          {isGroup ? <Users className="h-5 w-5" /> : customerInitial}
        </AvatarFallback>
      </Avatar>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center justify-between gap-2">
          <span className={cn('font-medium truncate', hasUnread && !isClosed && 'font-semibold')}>
            {customerName}
          </span>
          <div className="flex items-center gap-2 flex-shrink-0">
            <span className="text-xs text-muted-foreground">
              {lastMessageTime}
            </span>
            {/* LINE OA style green dot - shows when has unread messages */}
            {hasUnread && !isClosed && (
              <span className="h-3 w-3 rounded-full bg-[#06C755]" />
            )}
          </div>
        </div>

        {/* Last message preview */}
        {lastMessagePreview ? (
          <p className="text-xs text-muted-foreground truncate mt-0.5">
            {lastMessagePreview}
          </p>
        ) : (
          <div className="flex items-center gap-1.5 mt-0.5">
            <MessageCircle className={cn('h-3 w-3 flex-shrink-0', channelColor)} />
            <span className="text-xs text-muted-foreground truncate">
              {conversation.message_count} ข้อความ
            </span>
          </div>
        )}
      </div>
    </button>
  );
});
