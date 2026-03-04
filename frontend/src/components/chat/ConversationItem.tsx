import { memo, useCallback } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { MessageCircle, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useChannelInfo } from '@/hooks/useChannelInfo';
import type { Conversation } from '@/types/api';

// Short time format for Thai (e.g., "16n." instead of "16 minutes")
function formatTimeShort(date: Date): string {
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMins < 1) return 'now';
  if (diffMins < 60) return `${diffMins}m`;
  if (diffHours < 24) return `${diffHours}h`;
  if (diffDays < 7) return `${diffDays}d`;
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

const channelColors: Record<string, string> = {
  line: 'text-[#06C755]',
  facebook: 'text-[#0084FF]',
  telegram: 'text-[#0088CC]',
  demo: 'text-destructive',
};

export interface ConversationItemProps {
  conversation: Conversation;
  isSelected: boolean;
  onClick: (conversation: Conversation) => void;
}

/**
 * T029: Single conversation row component
 * Displays customer avatar, name, last message preview, and unread badge
 */
export const ConversationItem = memo(function ConversationItemInner({
  conversation,
  isSelected,
  onClick,
}: ConversationItemProps) {
  // Channel detection - using centralized hook
  const { isTelegram, isGroup } = useChannelInfo(conversation);

  const isClosed = conversation.status === 'closed';
  const hasUnread = conversation.unread_count > 0;

  // Display name: group title for telegram groups, otherwise customer name
  const customerName = isGroup
    ? conversation.telegram_chat_title || 'Telegram Group'
    : conversation.customer_profile?.display_name || 'Customer';

  const customerInitial = customerName.charAt(0).toUpperCase();

  // Format time in short form
  const lastMessageTime = conversation.last_message_at
    ? formatTimeShort(new Date(conversation.last_message_at))
    : null;

  // Last message preview
  const lastMessagePreview = conversation.last_message?.content || null;

  const channelColor = channelColors[conversation.channel_type] || 'text-muted-foreground';

  // Memoize click handler to prevent re-creation
  const handleClick = useCallback(() => {
    onClick(conversation);
  }, [onClick, conversation]);

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
      {/* Avatar with channel indicator */}
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
              {conversation.message_count} messages
            </span>
          </div>
        )}
      </div>
    </button>
  );
}, (prev, next) =>
  prev.conversation.id === next.conversation.id &&
  prev.conversation.last_message_at === next.conversation.last_message_at &&
  prev.conversation.unread_count === next.conversation.unread_count &&
  prev.conversation.status === next.conversation.status &&
  prev.conversation.last_message?.content === next.conversation.last_message?.content &&
  prev.conversation.customer_profile?.display_name === next.conversation.customer_profile?.display_name &&
  prev.conversation.customer_profile?.picture_url === next.conversation.customer_profile?.picture_url &&
  prev.isSelected === next.isSelected &&
  prev.onClick === next.onClick
);
