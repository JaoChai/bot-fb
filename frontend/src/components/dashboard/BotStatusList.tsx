import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { Plus, MessageSquare, MessagesSquare, ChevronDown, ChevronUp } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { ChannelIcon } from '@/components/ui/channel-icon';
import { cn } from '@/lib/utils';
import type { DashboardBotSummary } from '@/types/api';

interface BotStatusListProps {
  bots: DashboardBotSummary[];
}

const statusDot: Record<string, string> = {
  active: 'bg-green-500',
  inactive: 'bg-gray-400',
  paused: 'bg-yellow-500',
};

function isActiveBot(bot: DashboardBotSummary): boolean {
  return bot.messages_today > 0 || bot.active_conversations > 0 || bot.status === 'active';
}

export function BotStatusList({ bots }: BotStatusListProps) {
  const [showInactive, setShowInactive] = useState(false);

  const { activeBots, inactiveBots } = useMemo(() => {
    const a: DashboardBotSummary[] = [];
    const b: DashboardBotSummary[] = [];
    for (const bot of bots) (isActiveBot(bot) ? a : b).push(bot);
    return { activeBots: a, inactiveBots: b };
  }, [bots]);

  const visibleBots = showInactive ? [...activeBots, ...inactiveBots] : activeBots;

  return (
    <Card className="h-full">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">บอทที่ใช้งาน ({activeBots.length})</CardTitle>
      </CardHeader>
      <CardContent className="space-y-0">
        {bots.length === 0 ? (
          <div className="flex flex-col items-center py-6 text-center">
            <p className="text-sm text-muted-foreground mb-3">ยังไม่มีบอท</p>
            <Button size="sm" asChild>
              <Link to="/connections/add">
                <Plus className="h-3 w-3 mr-1" />
                สร้างบอทแรก
              </Link>
            </Button>
          </div>
        ) : (
          <>
            {visibleBots.map((bot, index) => (
              <div key={bot.id}>
                {index > 0 && <Separator className="my-2" />}
                <Link
                  to={`/chat?botId=${bot.id}`}
                  className="flex items-center gap-3 py-2 px-1 rounded-md hover:bg-muted/50 transition-colors"
                >
                  <div className="flex items-center gap-2 flex-1 min-w-0">
                    <span
                      className={cn(
                        'h-2 w-2 rounded-full flex-shrink-0',
                        statusDot[bot.status] ?? 'bg-gray-400'
                      )}
                    />
                    <ChannelIcon channel={bot.channel_type} className="h-4 w-4 flex-shrink-0" />
                    <span className="text-sm font-medium truncate">{bot.name}</span>
                  </div>
                  <div className="flex items-center gap-3 text-xs text-muted-foreground flex-shrink-0">
                    <span className="flex items-center gap-1">
                      <MessagesSquare className="h-3 w-3" />
                      {bot.conversation_count}
                    </span>
                    <span className="flex items-center gap-1">
                      <MessageSquare className="h-3 w-3" />
                      {bot.messages_today}
                    </span>
                  </div>
                </Link>
              </div>
            ))}

            {inactiveBots.length > 0 && (
              <>
                <Separator className="my-2" />
                <button
                  onClick={() => setShowInactive(!showInactive)}
                  className="flex items-center gap-1 w-full py-1 px-1 text-xs text-muted-foreground hover:text-foreground transition-colors"
                >
                  {showInactive ? (
                    <ChevronUp className="h-3 w-3" />
                  ) : (
                    <ChevronDown className="h-3 w-3" />
                  )}
                  และอีก {inactiveBots.length} บอทที่ไม่ใช้งาน
                </button>
              </>
            )}

            <Separator className="my-2" />
            <div className="pt-1">
              <Button variant="outline" size="sm" className="w-full" asChild>
                <Link to="/connections/add">
                  <Plus className="h-3 w-3 mr-1" />
                  สร้างบอทใหม่
                </Link>
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
