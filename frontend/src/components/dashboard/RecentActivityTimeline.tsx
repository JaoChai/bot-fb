import {
  Users,
  UserCheck,
  Bot,
  MessageSquare,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { DashboardActivity, DashboardActivityType } from '@/types/api';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';

interface RecentActivityTimelineProps {
  activities: DashboardActivity[];
}

const activityConfig: Record<
  DashboardActivityType,
  {
    icon: typeof Users;
    color: string;
    bgColor: string;
  }
> = {
  handover_started: {
    icon: Users,
    color: 'text-orange-600 dark:text-orange-400',
    bgColor: 'bg-orange-100 dark:bg-orange-950/40',
  },
  handover_resolved: {
    icon: UserCheck,
    color: 'text-emerald-600 dark:text-emerald-400',
    bgColor: 'bg-emerald-100 dark:bg-emerald-950/40',
  },
  bot_created: {
    icon: Bot,
    color: 'text-blue-600 dark:text-blue-400',
    bgColor: 'bg-blue-100 dark:bg-blue-950/40',
  },
  bot_updated: {
    icon: Bot,
    color: 'text-gray-600 dark:text-gray-400',
    bgColor: 'bg-gray-100 dark:bg-gray-800/40',
  },
  conversation_started: {
    icon: MessageSquare,
    color: 'text-emerald-600 dark:text-emerald-400',
    bgColor: 'bg-emerald-100 dark:bg-emerald-950/40',
  },
};

export function RecentActivityTimeline({
  activities,
}: RecentActivityTimelineProps) {
  if (activities.length === 0) {
    return (
      <div className="rounded-xl border bg-card p-6 shadow-sm">
        <h3 className="mb-4 text-base font-semibold">กิจกรรมล่าสุด</h3>
        <div className="flex flex-col items-center justify-center py-8 text-center">
          <div className="mb-3 rounded-full bg-muted p-3">
            <MessageSquare className="h-6 w-6 text-muted-foreground/50" />
          </div>
          <p className="text-sm text-muted-foreground">
            ยังไม่มีกิจกรรมล่าสุด
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-xl border bg-card p-6 shadow-sm">
      <h3 className="mb-4 text-base font-semibold">กิจกรรมล่าสุด</h3>
      <div className="space-y-4">
        {activities.map((activity, index) => {
          const config = activityConfig[activity.type] || {
            icon: MessageSquare,
            color: 'text-gray-600 dark:text-gray-400',
            bgColor: 'bg-gray-100 dark:bg-gray-800/40',
          };
          const Icon = config.icon;

          return (
            <div key={activity.id} className="flex gap-3">
              {/* Timeline indicator */}
              <div className="flex flex-col items-center">
                <div
                  className={cn(
                    'flex h-8 w-8 items-center justify-center rounded-full',
                    config.bgColor,
                  )}
                >
                  <Icon className={cn('h-4 w-4', config.color)} />
                </div>
                {index < activities.length - 1 && (
                  <div className="w-0.5 flex-1 bg-border mt-1" />
                )}
              </div>

              {/* Content */}
              <div className="flex-1 pb-4">
                <div className="flex items-center justify-between">
                  <p className="text-sm font-medium">{activity.title}</p>
                  <span className="text-xs text-muted-foreground">
                    {formatDistanceToNow(new Date(activity.created_at), {
                      addSuffix: true,
                      locale: th,
                    })}
                  </span>
                </div>
                {activity.bot_name && (
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {activity.bot_name}
                  </p>
                )}
                {activity.description && (
                  <p className="text-sm text-muted-foreground mt-1">
                    {activity.description}
                  </p>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
