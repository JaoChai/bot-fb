import {
  CheckCircle2,
  XCircle,
  Users,
  UserCheck,
  Sparkles,
  Bot,
  MessageSquare,
  FlaskConical,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    icon: typeof CheckCircle2;
    color: string;
    bgColor: string;
  }
> = {
  evaluation_started: {
    icon: FlaskConical,
    color: 'text-blue-600',
    bgColor: 'bg-blue-100',
  },
  evaluation_completed: {
    icon: CheckCircle2,
    color: 'text-green-600',
    bgColor: 'bg-green-100',
  },
  evaluation_failed: {
    icon: XCircle,
    color: 'text-red-600',
    bgColor: 'bg-red-100',
  },
  handover_started: {
    icon: Users,
    color: 'text-orange-600',
    bgColor: 'bg-orange-100',
  },
  handover_resolved: {
    icon: UserCheck,
    color: 'text-green-600',
    bgColor: 'bg-green-100',
  },
  improvement_started: {
    icon: Sparkles,
    color: 'text-purple-600',
    bgColor: 'bg-purple-100',
  },
  improvement_applied: {
    icon: CheckCircle2,
    color: 'text-purple-600',
    bgColor: 'bg-purple-100',
  },
  bot_created: {
    icon: Bot,
    color: 'text-blue-600',
    bgColor: 'bg-blue-100',
  },
  bot_updated: {
    icon: Bot,
    color: 'text-gray-600',
    bgColor: 'bg-gray-100',
  },
  conversation_started: {
    icon: MessageSquare,
    color: 'text-green-600',
    bgColor: 'bg-green-100',
  },
};

export function RecentActivityTimeline({
  activities,
}: RecentActivityTimelineProps) {
  if (activities.length === 0) {
    return (
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">กิจกรรมล่าสุด</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-8 text-center">
            <MessageSquare className="h-12 w-12 text-muted-foreground/30 mb-3" />
            <p className="text-sm text-muted-foreground">
              ยังไม่มีกิจกรรมล่าสุด
            </p>
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">กิจกรรมล่าสุด</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="space-y-4">
          {activities.map((activity, index) => {
            const config = activityConfig[activity.type] || {
              icon: MessageSquare,
              color: 'text-gray-600',
              bgColor: 'bg-gray-100',
            };
            const Icon = config.icon;

            return (
              <div key={activity.id} className="flex gap-3">
                {/* Timeline indicator */}
                <div className="flex flex-col items-center">
                  <div
                    className={cn(
                      'flex h-8 w-8 items-center justify-center rounded-full',
                      config.bgColor
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
      </CardContent>
    </Card>
  );
}
