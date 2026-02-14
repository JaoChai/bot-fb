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

  return (
    <div className="flex-shrink-0 sticky top-0 z-10 flex items-center justify-between p-2 sm:p-3 border-b bg-background">
      <div className="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
        {/* Back button - mobile only */}
        {onBack && (
          <Button
            variant="outline"
            size="icon"
            className="md:hidden h-10 w-10 min-h-[40px] min-w-[40px] flex-shrink-0 border-2"
            onClick={onBack}
            aria-label="Back to conversation list"
          >
            <ArrowLeft className="h-5 w-5" />
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
            {isGroup ? <Users className="h-5 w-5" /> : customerInitial}
          </AvatarFallback>
        </Avatar>

        {/* Customer info */}
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h2 className="font-semibold text-sm sm:text-base truncate max-w-[120px] sm:max-w-none">
              {customerName}
            </h2>
            {/* Unread indicator */}
            {conversation.unread_count > 0 && conversation.status !== 'closed' && (
              <span className="h-3 w-3 rounded-full bg-[#06C755] flex-shrink-0" />
            )}
          </div>
          <p className="text-xs text-muted-foreground truncate">
            {displayName} - {conversation.message_count} messages
          </p>
        </div>
      </div>

      <div className="flex items-center gap-1 sm:gap-2 flex-shrink-0">
        {/* Handover controls - only for channels that support it */}
        {showHandoverControls && supportsHandover && onToggleHandover && (
          <Button
            variant={conversation.is_handover ? 'default' : 'outline'}
            size="sm"
            onClick={onToggleHandover}
            disabled={isToggleLoading}
          >
            {isToggleLoading ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : conversation.is_handover ? (
              <>
                <Bot className="h-4 w-4 mr-1" />
                Enable Bot
              </>
            ) : (
              <>
                <Headphones className="h-4 w-4 mr-1" />
                Take Over
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
            className="xl:hidden"
            onClick={onShowInfo}
          >
            <Info className="h-4 w-4" />
          </Button>
        )}
      </div>
    </div>
  );
});

