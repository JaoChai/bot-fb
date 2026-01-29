import { useCallback } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { useToggleHandover } from '@/hooks/useConversations';
import { useChannelInfo } from '@/hooks/useChannelInfo';
import { useCountdown } from '@/hooks/useCountdown';
import { useToast } from '@/hooks/use-toast';
import { Bot, Headphones, Timer, Ban } from 'lucide-react';
import type { Conversation } from '@/types/api';

interface BotControlProps {
  botId: number;
  conversation: Conversation;
}

/**
 * Bot control component for toggling handover mode
 * Extracted from CustomerInfoPanel for reusability
 */
export function BotControl({ botId, conversation }: BotControlProps) {
  const { toast } = useToast();
  const toggleHandover = useToggleHandover(botId);

  // Channel detection - using centralized hook
  const { isTelegram, supportsHandover } = useChannelInfo(conversation);

  // Auto-enable countdown - using centralized hook
  const { formatted: countdownFormatted, isActive: isCountdownActive } = useCountdown({
    targetTime: conversation.bot_auto_enable_at,
    enabled: conversation.is_handover,
  });

  const handleToggleBot = useCallback(async () => {
    try {
      // Toggle always uses 30 min auto-enable (permanent disable handled by checkbox)
      await toggleHandover.mutateAsync({
        conversationId: conversation.id,
        autoEnableMinutes: 30,
      });

      if (conversation.is_handover) {
        toast({
          title: 'Bot Enabled',
          description: 'Bot will respond to messages in this conversation',
        });
      } else {
        toast({
          title: 'Handover Mode',
          description: 'You can respond directly. Bot auto-enables in 30 minutes',
        });
      }
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to toggle bot mode',
        variant: 'destructive',
      });
    }
  }, [toggleHandover, conversation.id, conversation.is_handover, toast]);

  // Channels without handover support: Human Agent Mode (no bot toggle)
  if (!supportsHandover) {
    const borderColor = isTelegram ? 'border-[#0088CC]/50' : 'border-muted';
    const bgColor = isTelegram ? 'bg-[#0088CC]/5' : 'bg-muted/5';
    const iconColor = isTelegram ? 'text-[#0088CC]' : 'text-muted-foreground';

    return (
      <Card className={`border-2 border-dashed ${borderColor} ${bgColor}`}>
        <CardContent className="p-4 space-y-2">
          <div className="flex items-center gap-2">
            <Headphones className={`h-5 w-5 ${iconColor}`} />
            <span className={`font-medium ${iconColor}`}>Human Agent Mode</span>
          </div>
          <p className="text-xs text-muted-foreground">
            This channel uses Human Agent mode only. No auto-responses.
          </p>
        </CardContent>
      </Card>
    );
  }

  // Other channels: Bot toggle with countdown
  return (
    <Card className={conversation.is_handover ? 'border-2 border-dashed' : 'border-2 border-foreground'}>
      <CardContent className="p-4 space-y-3">
        <div className="flex items-center gap-2">
          {conversation.is_handover ? (
            <Headphones className="h-5 w-5 text-muted-foreground" />
          ) : (
            <Bot className="h-5 w-5" />
          )}
          <span className="font-medium">
            {conversation.is_handover ? 'Handover Mode' : 'Bot Active'}
          </span>
        </div>

        <div className="flex items-center justify-between">
          <Label htmlFor="bot-toggle" className="text-sm">
            {conversation.is_handover ? 'Enable Bot' : 'Bot is Active'}
          </Label>
          <Switch
            id="bot-toggle"
            checked={!conversation.is_handover}
            onCheckedChange={handleToggleBot}
            disabled={toggleHandover.isPending}
          />
        </div>

        {/* Permanent disable option - show when bot is ON, click to disable immediately */}
        {!conversation.is_handover && (
          <div className="flex items-center gap-2">
            <Checkbox
              id="permanent-disable"
              checked={false}
              disabled={toggleHandover.isPending}
              onCheckedChange={async (checked) => {
                if (checked !== true) return;

                try {
                  // Immediately disable bot permanently (no auto-enable)
                  await toggleHandover.mutateAsync({
                    conversationId: conversation.id,
                    autoEnableMinutes: 0,
                  });

                  toast({
                    title: 'Bot Disabled Permanently',
                    description: 'Bot will not respond until manually enabled',
                  });
                } catch {
                  toast({
                    title: 'Error',
                    description: 'Failed to disable bot',
                    variant: 'destructive',
                  });
                }
              }}
            />
            <Label htmlFor="permanent-disable" className="text-sm text-muted-foreground cursor-pointer">
              Disable permanently (no auto-enable)
            </Label>
          </div>
        )}

        {/* Auto-enable countdown or permanent indicator */}
        {conversation.is_handover && (
          isCountdownActive ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Timer className="h-4 w-4" />
              <span>Auto-enables in {countdownFormatted}</span>
            </div>
          ) : !conversation.bot_auto_enable_at && (
            <div className="flex items-center gap-2 text-sm text-destructive">
              <Ban className="h-4 w-4" />
              <span>Permanently disabled</span>
            </div>
          )
        )}

        <p className="text-xs text-muted-foreground">
          {conversation.is_handover
            ? 'Bot is paused. You can respond directly to customer.'
            : 'Bot will auto-respond to messages.'}
        </p>
      </CardContent>
    </Card>
  );
}
