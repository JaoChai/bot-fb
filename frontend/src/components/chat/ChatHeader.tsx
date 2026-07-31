/**
 * T023: ChatHeader component
 * Customer name/avatar, handover toggle, back button
 */
import { memo } from 'react';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ArrowLeft, Info, Bot, Headphones, Loader2, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useChannelInfo } from '@/hooks/useChannelInfo';
import { VipBadge } from '@/components/conversation/VipBadge';
import { getVipNote } from '@/lib/vip';
import { ConnectionIndicator } from './ConnectionIndicator';
import type { Conversation } from '@/types/api';

export interface ChatHeaderProps {
  conversation: Conversation;
  onBack?: () => void;
  onShowInfo?: () => void;
  onToggleHandover?: () => void;
  isToggleLoading?: boolean;
  showHandoverControls?: boolean;
  /** Additional action buttons to render after handover controls */
  actions?: React.ReactNode;
}

export const ChatHeader = memo(function ChatHeader({
  conversation,
  onBack,
  onShowInfo,
  onToggleHandover,
  isToggleLoading = false,
  showHandoverControls = true,
  actions,
}: ChatHeaderProps) {
  // Channel detection - using centralized hook
  const { isTelegram, isGroup, supportsHandover, displayName } = useChannelInfo(conversation);

  // Display name: group title for telegram groups, otherwise customer name
  const customerName = isGroup
    ? conversation.telegram_chat_title || 'Telegram Group'
    : conversation.customer_profile?.display_name || 'Customer';
  const customerInitial = customerName.charAt(0).toUpperCase();
  const vip = getVipNote(conversation.memory_notes);

  return (
    <div className="flex-shrink-0 sticky top-0 z-10 flex items-center justify-between p-2 sm:p-3 border-b bg-background">
      <div className="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
        {/* Back button - mobile only */}
        {onBack && (
          <Button
            variant="outline"
            size="icon"
            className="md:hidden size-11 min-h-[44px] min-w-[44px] flex-shrink-0 border-2"
            onClick={onBack}
            aria-label="Back to conversation list"
          >
            <ArrowLeft className="size-5" />
          </Button>
        )}

        {/* Avatar */}
        <Avatar
          className={cn(
            'h-8 w-8 sm:h-10 sm:w-10 flex-shrink-0',
            isTelegram && 'bg-[#0088CC]/10'
          )}
        >
          <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
          <AvatarFallback className={isTelegram ? 'bg-[#0088CC]/10 text-[#0088CC]' : undefined}>
            {isGroup ? <Users className="size-5" /> : customerInitial}
          </AvatarFallback>
        </Avatar>

        {/* Customer info */}
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h2 className="font-semibold text-sm sm:text-base truncate">
              {customerName}
            </h2>
            {vip && <VipBadge variant={vip.variant} tooltipContent={vip.content} />}
            {/* Unread indicator */}
            {conversation.unread_count > 0 && conversation.status !== 'closed' && (
              <span className="size-3 rounded-full bg-[#06C755] flex-shrink-0" />
            )}
          </div>
          <p className="text-xs text-muted-foreground truncate">
            {displayName} - {conversation.message_count} messages
          </p>
        </div>
      </div>

      <div className="flex items-center gap-2 flex-shrink-0">
        <ConnectionIndicator />

        {/* Handover controls - only for channels that support it */}
        {/* max-sm: แตะเฉพาะต่ำกว่า 640px เพื่อให้ได้ touch target 44px — ตั้งแต่
            640px ขึ้นไปปล่อยให้ size="sm" ของ shadcn (h-8 px-3) ทำงานเดิมทุกพิกเซล */}
        {showHandoverControls && supportsHandover && onToggleHandover && (
          <Button
            variant={conversation.is_handover ? 'default' : 'outline'}
            size="sm"
            className="max-sm:size-11 max-sm:p-0"
            onClick={onToggleHandover}
            disabled={isToggleLoading}
            aria-label={conversation.is_handover ? 'Enable Bot' : 'Take Over'}
          >
            {isToggleLoading ? (
              <Loader2 className="size-4 animate-spin" />
            ) : conversation.is_handover ? (
              <>
                <Bot className="size-4 sm:mr-1" />
                <span className="hidden sm:inline">Enable Bot</span>
              </>
            ) : (
              <>
                <Headphones className="size-4 sm:mr-1" />
                <span className="hidden sm:inline">Take Over</span>
              </>
            )}
          </Button>
        )}

        {/* Additional action buttons */}
        {actions}

        {/* Info button (for tablet) */}
        {onShowInfo && (
          <Button
            variant="outline"
            size="icon"
            className="hidden sm:inline-flex xl:hidden"
            onClick={onShowInfo}
            aria-label="ข้อมูลลูกค้า"
          >
            <Info className="size-4" />
          </Button>
        )}
      </div>
    </div>
  );
});

