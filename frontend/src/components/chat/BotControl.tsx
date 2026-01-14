import { useState, useEffect, useCallback } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { useToggleHandover } from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import { toast as sonnerToast } from 'sonner';
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
  const isTelegram = conversation.channel_type === 'telegram';

  // Permanent disable option (no auto-enable)
  const [permanentDisable, setPermanentDisable] = useState(false);

  // Auto-enable countdown
  const [remainingSeconds, setRemainingSeconds] = useState<number | null>(
    conversation.bot_auto_enable_remaining_seconds
  );

  // Update countdown - optimized to pause when tab is hidden
  useEffect(() => {
    if (!conversation.is_handover || !conversation.bot_auto_enable_at) {
      setRemainingSeconds(null);
      return;
    }

    const targetTime = new Date(conversation.bot_auto_enable_at).getTime();
    let intervalId: ReturnType<typeof setInterval> | null = null;

    const updateCountdown = () => {
      const now = Date.now();
      const diff = Math.max(0, Math.floor((targetTime - now) / 1000));
      setRemainingSeconds(diff);
      return diff;
    };

    const startInterval = () => {
      if (intervalId) return;
      const diff = updateCountdown();
      if (diff <= 0) return;

      intervalId = setInterval(() => {
        const remaining = updateCountdown();
        if (remaining <= 0 && intervalId) {
          clearInterval(intervalId);
          intervalId = null;
        }
      }, 1000);
    };

    const stopInterval = () => {
      if (intervalId) {
        clearInterval(intervalId);
        intervalId = null;
      }
    };

    const handleVisibilityChange = () => {
      if (document.hidden) {
        stopInterval();
      } else {
        startInterval();
      }
    };

    if (!document.hidden) {
      startInterval();
    }

    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      stopInterval();
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [conversation.is_handover, conversation.bot_auto_enable_at]);

  const formatCountdown = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  };

  const handleToggleBot = useCallback(async () => {
    try {
      const autoEnableMinutes = conversation.is_handover ? 30 : (permanentDisable ? 0 : 30);

      await toggleHandover.mutateAsync({
        conversationId: conversation.id,
        autoEnableMinutes,
      });

      if (conversation.is_handover) {
        toast({
          title: 'Bot Enabled',
          description: 'Bot will respond to messages in this conversation',
        });
      } else {
        toast({
          title: permanentDisable ? 'Bot Disabled Permanently' : 'Handover Mode',
          description: permanentDisable
            ? 'Bot will not respond until manually enabled'
            : 'You can respond directly. Bot auto-enables in 30 minutes',
        });
      }

      setPermanentDisable(false);
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to toggle bot mode',
        variant: 'destructive',
      });
    }
  }, [toggleHandover, conversation.id, conversation.is_handover, permanentDisable, toast]);

  // Telegram: Human Agent Mode (no bot toggle)
  if (isTelegram) {
    return (
      <Card className="border-2 border-dashed border-[#0088CC]/50 bg-[#0088CC]/5">
        <CardContent className="p-4 space-y-2">
          <div className="flex items-center gap-2">
            <Headphones className="h-5 w-5 text-[#0088CC]" />
            <span className="font-medium text-[#0088CC]">Human Agent Mode</span>
          </div>
          <p className="text-xs text-muted-foreground">
            Telegram uses Human Agent mode only. No auto-responses.
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

        {/* Permanent disable option - show when bot is ON (about to turn off) */}
        {!conversation.is_handover && (
          <div className="flex items-center gap-2">
            <Checkbox
              id="permanent-disable"
              checked={permanentDisable}
              onCheckedChange={(checked) => {
                const isChecked = checked === true;
                setPermanentDisable(isChecked);

                if (isChecked) {
                  sonnerToast.info('Bot จะปิดถาวรเมื่อคุณปิด Toggle', {
                    description: 'ไม่มีการเปิดกลับอัตโนมัติ',
                  });
                } else {
                  sonnerToast.info('Bot จะเปิดกลับอัตโนมัติใน 30 นาที', {
                    description: 'หลังจากปิด Toggle',
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
          remainingSeconds !== null && remainingSeconds > 0 ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Timer className="h-4 w-4" />
              <span>Auto-enables in {formatCountdown(remainingSeconds)}</span>
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
