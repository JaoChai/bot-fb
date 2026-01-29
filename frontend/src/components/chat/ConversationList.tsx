import { useEffect, useRef } from 'react';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { Search, Loader2 } from 'lucide-react';
import { ConversationItem } from './ConversationItem';
import { getChannelInfo } from '@/hooks/useChannelInfo';
import type { Conversation } from '@/types/api';

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
  // Derive channel info from first conversation (all from same bot)
  const { isTelegram } = getChannelInfo(conversations[0]);

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
                onClick={onSelect}
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

