import { useCallback } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { useToggleHandover } from '@/hooks/useConversations';
import { useChannelInfo } from '@/hooks/useChannelInfo';
import { useToast } from '@/hooks/use-toast';
import { Bot, Headphones } from 'lucide-react';
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

  const handleToggleBot = useCallback(async () => {
    try {
      await toggleHandover.mutateAsync({
        conversationId: conversation.id,
        autoEnableMinutes: 0,
      });

      if (conversation.is_handover) {
        toast({
          title: 'Bot Enabled',
          description: 'Bot will respond to messages in this conversation',
        });
      } else {
        toast({
          title: 'Handover Mode',
          description: 'Bot is disabled until manually re-enabled',
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

  // Other channels: Bot toggle
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

        <p className="text-xs text-muted-foreground">
          {conversation.is_handover
            ? 'Bot is paused. You can respond directly to customer.'
            : 'Bot will auto-respond to messages.'}
        </p>
      </CardContent>
    </Card>
  );
}
