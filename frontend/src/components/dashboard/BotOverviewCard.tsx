import { Link } from 'react-router';
import {
  MessageSquare,
  MessagesSquare,
  Settings,
  Clock,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ChannelIcon } from '@/components/ui/channel-icon';
import { cn } from '@/lib/utils';
import type { DashboardBotSummary } from '@/types/api';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';

interface BotOverviewCardProps {
  bot: DashboardBotSummary;
}

const statusConfig = {
  active: { label: 'ทำงาน', variant: 'default' as const, className: 'bg-green-100 text-green-800' },
  inactive: { label: 'หยุดทำงาน', variant: 'secondary' as const, className: 'bg-gray-100 text-gray-800' },
  paused: { label: 'พักการใช้งาน', variant: 'outline' as const, className: 'bg-yellow-100 text-yellow-800' },
};

export function BotOverviewCard({ bot }: BotOverviewCardProps) {
  const status = statusConfig[bot.status];

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <ChannelIcon channel={bot.channel_type} />
            <CardTitle className="text-base">{bot.name}</CardTitle>
          </div>
          <Badge className={cn('font-normal', status.className)}>{status.label}</Badge>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Stats Row */}
        <div className="flex items-center gap-4 text-sm text-muted-foreground">
          <div className="flex items-center gap-1">
            <MessagesSquare className="h-4 w-4" />
            <span>{bot.conversation_count} สนทนา</span>
          </div>
          <div className="flex items-center gap-1">
            <MessageSquare className="h-4 w-4" />
            <span>{bot.messages_today} msg/วัน</span>
          </div>
        </div>

        {/* Last Active */}
        {bot.last_active_at && (
          <div className="flex items-center gap-1 text-xs text-muted-foreground">
            <Clock className="h-3 w-3" />
            <span>
              ใช้งานล่าสุด{' '}
              {formatDistanceToNow(new Date(bot.last_active_at), {
                addSuffix: true,
                locale: th,
              })}
            </span>
          </div>
        )}

        {/* Action Buttons */}
        <div className="flex gap-2 pt-1">
          <Button variant="outline" size="sm" asChild className="flex-1">
            <Link to={`/chat?botId=${bot.id}`}>ดูแชท</Link>
          </Button>
          <Button variant="outline" size="sm" asChild className="flex-1">
            <Link to={`/flows/editor?botId=${bot.id}`}>AI Flow</Link>
          </Button>
          <Button variant="ghost" size="sm" asChild>
            <Link to={`/bots/${bot.id}/settings`}>
              <Settings className="h-4 w-4" />
            </Link>
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
