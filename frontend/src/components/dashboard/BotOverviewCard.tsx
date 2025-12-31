import { Link } from 'react-router';
import {
  MessageSquare,
  MessagesSquare,
  Star,
  Settings,
  TestTube,
  Clock,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { DashboardBotSummary } from '@/types/api';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';

interface BotOverviewCardProps {
  bot: DashboardBotSummary;
}

const channelIcons: Record<string, string> = {
  line: '/line-icon.svg',
  facebook: '/facebook-icon.svg',
  testing: '',
};

const channelLabels: Record<string, string> = {
  line: 'LINE',
  facebook: 'Facebook',
  testing: 'Testing',
};

const statusConfig = {
  active: { label: 'ทำงาน', variant: 'default' as const, className: 'bg-green-100 text-green-800' },
  inactive: { label: 'หยุดทำงาน', variant: 'secondary' as const, className: 'bg-gray-100 text-gray-800' },
  paused: { label: 'พักการใช้งาน', variant: 'outline' as const, className: 'bg-yellow-100 text-yellow-800' },
};

export function BotOverviewCard({ bot }: BotOverviewCardProps) {
  const status = statusConfig[bot.status];
  const hasEvaluation = bot.latest_evaluation?.status === 'completed';
  const score = bot.latest_evaluation?.overall_score;

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            {bot.channel_type !== 'testing' && channelIcons[bot.channel_type] && (
              <img
                src={channelIcons[bot.channel_type]}
                alt={channelLabels[bot.channel_type]}
                className="h-5 w-5"
              />
            )}
            {bot.channel_type === 'testing' && (
              <TestTube className="h-5 w-5 text-muted-foreground" />
            )}
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
          {hasEvaluation && typeof score === 'number' && (
            <div className="flex items-center gap-1">
              <Star
                className={cn(
                  'h-4 w-4',
                  score >= 0.8 ? 'text-green-500' : score >= 0.6 ? 'text-yellow-500' : 'text-red-500'
                )}
              />
              <span>{Math.round(score * 100)}%</span>
            </div>
          )}
          {!hasEvaluation && (
            <div className="flex items-center gap-1 text-muted-foreground/60">
              <Star className="h-4 w-4" />
              <span>ยังไม่ประเมิน</span>
            </div>
          )}
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
          <Button variant="outline" size="sm" asChild>
            <Link to={`/evaluations?bot=${bot.id}`}>
              <TestTube className="h-4 w-4" />
            </Link>
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
