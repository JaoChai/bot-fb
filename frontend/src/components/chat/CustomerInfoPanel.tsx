import { useState, useEffect } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useToggleHandover } from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import { NotesPanel } from '@/components/conversation/NotesPanel';
import { TagsPanel } from '@/components/conversation/TagsPanel';
import {
  Bot,
  Headphones,
  Mail,
  Phone,
  Hash,
  Calendar,
  Clock,
  MessagesSquare,
  Timer,
} from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';
import type { Conversation } from '@/types/api';

const channelLabels: Record<string, string> = {
  line: 'LINE',
  facebook: 'Facebook',
  demo: 'Demo',
};

interface CustomerInfoPanelProps {
  botId: number;
  conversation: Conversation;
}

export function CustomerInfoPanel({ botId, conversation }: CustomerInfoPanelProps) {
  const { toast } = useToast();
  const customer = conversation.customer_profile;
  const toggleHandover = useToggleHandover(botId);

  // Auto-enable countdown
  const [remainingSeconds, setRemainingSeconds] = useState<number | null>(
    conversation.bot_auto_enable_remaining_seconds
  );

  // Update countdown
  useEffect(() => {
    if (!conversation.is_handover || !conversation.bot_auto_enable_at) {
      setRemainingSeconds(null);
      return;
    }

    // Calculate remaining seconds
    const targetTime = new Date(conversation.bot_auto_enable_at).getTime();
    const now = Date.now();
    const diff = Math.max(0, Math.floor((targetTime - now) / 1000));
    setRemainingSeconds(diff);

    if (diff <= 0) return;

    // Start countdown
    const interval = setInterval(() => {
      setRemainingSeconds((prev) => {
        if (prev === null || prev <= 1) {
          clearInterval(interval);
          return null;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [conversation.is_handover, conversation.bot_auto_enable_at]);

  // Format countdown
  const formatCountdown = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  };

  // Handle toggle bot
  const handleToggleBot = async () => {
    try {
      await toggleHandover.mutateAsync({ conversationId: conversation.id });
      toast({
        title: conversation.is_handover ? 'เปิด Bot แล้ว' : 'เปิดโหมดรอตอบ',
        description: conversation.is_handover
          ? 'Bot จะตอบข้อความในการสนทนานี้'
          : 'คุณสามารถตอบข้อความได้โดยตรง Bot จะเปิดอัตโนมัติใน 30 นาที',
      });
    } catch {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถสลับโหมด Bot ได้',
        variant: 'destructive',
      });
    }
  };

  return (
    <div className="p-4 space-y-6">
      {/* Customer Profile */}
      <div className="flex items-center gap-4">
        <Avatar className="h-16 w-16">
          <AvatarImage src={customer?.picture_url || undefined} />
          <AvatarFallback className="text-lg">
            {customer?.display_name?.charAt(0).toUpperCase() || '?'}
          </AvatarFallback>
        </Avatar>
        <div>
          <h3 className="font-semibold text-lg">
            {customer?.display_name || 'ลูกค้า'}
          </h3>
          <p className="text-sm text-muted-foreground">
            {channelLabels[conversation.channel_type]}
          </p>
        </div>
      </div>

      <Separator />

      {/* Bot Control */}
      <Card className={conversation.is_handover ? 'border-2 border-dashed' : 'border-2 border-foreground'}>
        <CardContent className="p-4 space-y-3">
          <div className="flex items-center gap-2">
            {conversation.is_handover ? (
              <Headphones className="h-5 w-5 text-muted-foreground" />
            ) : (
              <Bot className="h-5 w-5" />
            )}
            <span className="font-medium">
              {conversation.is_handover ? 'โหมดรอตอบ' : 'Bot เปิด'}
            </span>
          </div>

          <div className="flex items-center justify-between">
            <Label htmlFor="bot-toggle" className="text-sm">
              {conversation.is_handover ? 'เปิด Bot' : 'Bot เปิดอยู่'}
            </Label>
            <Switch
              id="bot-toggle"
              checked={!conversation.is_handover}
              onCheckedChange={handleToggleBot}
              disabled={toggleHandover.isPending}
            />
          </div>

          {/* Auto-enable countdown */}
          {conversation.is_handover && remainingSeconds !== null && remainingSeconds > 0 && (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Timer className="h-4 w-4" />
              <span>เปิดอัตโนมัติใน {formatCountdown(remainingSeconds)}</span>
            </div>
          )}

          <p className="text-xs text-muted-foreground">
            {conversation.is_handover
              ? 'Bot หยุดทำงาน คุณสามารถตอบลูกค้าได้โดยตรง'
              : 'Bot จะตอบข้อความโดยอัตโนมัติ'}
          </p>
        </CardContent>
      </Card>

      <Separator />

      {/* Contact Info */}
      <div className="space-y-3">
        <h4 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
          ข้อมูลติดต่อ
        </h4>

        {customer?.email && (
          <div className="flex items-center gap-2 text-sm">
            <Mail className="h-4 w-4 text-muted-foreground" />
            <span>{customer.email}</span>
          </div>
        )}

        {customer?.phone && (
          <div className="flex items-center gap-2 text-sm">
            <Phone className="h-4 w-4 text-muted-foreground" />
            <span>{customer.phone}</span>
          </div>
        )}

        <div className="flex items-center gap-2 text-sm">
          <Hash className="h-4 w-4 text-muted-foreground" />
          <span className="font-mono text-xs truncate">{conversation.external_customer_id}</span>
        </div>
      </div>

      <Separator />

      {/* Interaction Stats */}
      <div className="space-y-3">
        <h4 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
          สถิติการโต้ตอบ
        </h4>

        <div className="grid grid-cols-2 gap-3">
          <Card>
            <CardContent className="p-3">
              <div className="flex items-center gap-2">
                <MessagesSquare className="h-4 w-4 text-muted-foreground" />
                <div>
                  <p className="text-2xl font-bold">{conversation.message_count}</p>
                  <p className="text-xs text-muted-foreground">ข้อความ</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-3">
              <div className="flex items-center gap-2">
                <Hash className="h-4 w-4 text-muted-foreground" />
                <div>
                  <p className="text-2xl font-bold">{customer?.interaction_count || 1}</p>
                  <p className="text-xs text-muted-foreground">ครั้ง</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="flex items-center gap-2 text-sm">
          <Calendar className="h-4 w-4 text-muted-foreground" />
          <span>
            เริ่มคุยครั้งแรก:{' '}
            {customer?.first_interaction_at
              ? formatDistanceToNow(new Date(customer.first_interaction_at), {
                  addSuffix: true,
                  locale: th,
                })
              : 'ไม่ระบุ'}
          </span>
        </div>

        <div className="flex items-center gap-2 text-sm">
          <Clock className="h-4 w-4 text-muted-foreground" />
          <span>
            ข้อความล่าสุด:{' '}
            {conversation.last_message_at
              ? formatDistanceToNow(new Date(conversation.last_message_at), {
                  addSuffix: true,
                  locale: th,
                })
              : 'ไม่ระบุ'}
          </span>
        </div>
      </div>

      <Separator />

      {/* Tags Panel */}
      <TagsPanel
        botId={botId}
        conversationId={conversation.id}
        currentTags={conversation.tags || []}
      />

      <Separator />

      {/* Notes Panel */}
      <NotesPanel botId={botId} conversationId={conversation.id} />
    </div>
  );
}
