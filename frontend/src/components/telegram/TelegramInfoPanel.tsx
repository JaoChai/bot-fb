import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { NotesPanel } from '@/components/conversation/NotesPanel';
import { TagsPanel } from '@/components/conversation/TagsPanel';
import {
  Users,
  User,
  Mail,
  Phone,
  Hash,
  Calendar,
  Clock,
  MessagesSquare,
  Headphones,
} from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';
import type { Conversation } from '@/types/api';

interface TelegramInfoPanelProps {
  botId: number;
  conversation: Conversation;
}

export function TelegramInfoPanel({ botId, conversation }: TelegramInfoPanelProps) {
  const customer = conversation.customer_profile;
  const isGroup = conversation.telegram_chat_type === 'group' || conversation.telegram_chat_type === 'supergroup';

  return (
    <div className="p-4 space-y-6">
      {/* Customer/Chat Profile */}
      <div className="flex items-center gap-4">
        <Avatar className="h-16 w-16">
          <AvatarImage src={customer?.picture_url || undefined} />
          <AvatarFallback className="text-lg bg-[#0088CC]/10 text-[#0088CC]">
            {isGroup ? (
              <Users className="h-6 w-6" />
            ) : (
              customer?.display_name?.charAt(0).toUpperCase() || '?'
            )}
          </AvatarFallback>
        </Avatar>
        <div>
          <h3 className="font-semibold text-lg">
            {isGroup
              ? conversation.telegram_chat_title || 'Group'
              : customer?.display_name || 'Telegram User'}
          </h3>
          <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
            {isGroup ? (
              <>
                <Users className="h-3.5 w-3.5" />
                <span>
                  {conversation.telegram_chat_type === 'supergroup' ? 'Supergroup' : 'Group'}
                </span>
              </>
            ) : (
              <>
                <User className="h-3.5 w-3.5" />
                <span>Private Chat</span>
              </>
            )}
          </div>
        </div>
      </div>

      <Separator />

      {/* Human Agent Mode Indicator */}
      <Card className="border-2 border-dashed border-[#0088CC]/50 bg-[#0088CC]/5">
        <CardContent className="p-4 space-y-2">
          <div className="flex items-center gap-2">
            <Headphones className="h-5 w-5 text-[#0088CC]" />
            <span className="font-medium text-[#0088CC]">Human Agent Mode</span>
          </div>
          <p className="text-xs text-muted-foreground">
            Telegram ใช้ระบบ Human Agent เท่านั้น ไม่มี Bot ตอบอัตโนมัติ
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
