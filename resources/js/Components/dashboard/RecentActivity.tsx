import * as React from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import { cn } from '@/Lib/utils';

interface RecentActivityProps {
  conversations: Array<{
    id: number;
    customer_name: string;
    last_message: string;
    channel_type: string;
    updated_at: string;
  }>;
  botId?: number;
}

function formatRelativeTime(dateString: string): string {
  const date = new Date(dateString);
  const now = new Date();
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

  if (diffInSeconds < 60) {
    return 'เมื่อสักครู่';
  }

  const diffInMinutes = Math.floor(diffInSeconds / 60);
  if (diffInMinutes < 60) {
    return `${diffInMinutes} นาทีที่แล้ว`;
  }

  const diffInHours = Math.floor(diffInMinutes / 60);
  if (diffInHours < 24) {
    return `${diffInHours} ชั่วโมงที่แล้ว`;
  }

  const diffInDays = Math.floor(diffInHours / 24);
  if (diffInDays < 7) {
    return `${diffInDays} วันที่แล้ว`;
  }

  return date.toLocaleDateString('th-TH', {
    day: 'numeric',
    month: 'short',
  });
}

function truncateMessage(message: string, maxLength: number = 50): string {
  if (message.length <= maxLength) {
    return message;
  }
  return message.slice(0, maxLength) + '...';
}

function RecentActivity({ conversations, botId }: RecentActivityProps) {
  const chatUrl = botId ? `/bots/${botId}/chat` : '/chat';

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base font-medium">
          การสนทนาล่าสุด
        </CardTitle>
      </CardHeader>
      <CardContent>
        {conversations.length > 0 ? (
          <div className="space-y-1">
            {conversations.map((conversation) => (
              <Link
                key={conversation.id}
                href={`${chatUrl}?conversation=${conversation.id}`}
                className={cn(
                  'flex items-start gap-3 rounded-lg p-2 -mx-2',
                  'transition-colors hover:bg-accent cursor-pointer'
                )}
              >
                <div className="mt-0.5 shrink-0">
                  <ChannelIcon
                    channel={conversation.channel_type}
                    className="h-5 w-5"
                  />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center justify-between gap-2">
                    <p className="truncate text-sm font-medium">
                      {conversation.customer_name}
                    </p>
                    <span className="text-muted-foreground shrink-0 text-xs">
                      {formatRelativeTime(conversation.updated_at)}
                    </span>
                  </div>
                  <p className="text-muted-foreground truncate text-sm">
                    {truncateMessage(conversation.last_message)}
                  </p>
                </div>
              </Link>
            ))}

            <Link
              href={chatUrl}
              className={cn(
                'flex items-center justify-center gap-1 pt-3 text-sm',
                'text-muted-foreground hover:text-foreground transition-colors'
              )}
            >
              <span>ดูทั้งหมด</span>
              <ArrowRight className="h-4 w-4" />
            </Link>
          </div>
        ) : (
          <div className="flex h-[200px] items-center justify-center">
            <p className="text-muted-foreground text-sm">ยังไม่มีการสนทนา</p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export { RecentActivity };
export type { RecentActivityProps };
