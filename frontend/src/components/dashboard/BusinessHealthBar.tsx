import { cn } from '@/lib/utils';
import { Activity, AlertTriangle, XCircle } from 'lucide-react';
import type { DashboardBotSummary, DashboardAlerts } from '@/types/api';

interface BusinessHealthBarProps {
  bots: DashboardBotSummary[];
  alerts: DashboardAlerts;
}

export function BusinessHealthBar({ bots, alerts }: BusinessHealthBarProps) {
  // Calculate metrics
  const activeBotCount = bots.filter((b) => b.status === 'active').length;
  const totalBotCount = bots.length;
  const handoverCount = alerts.handover_conversations.length;

  // Determine status and styling
  const isNoBots = totalBotCount === 0;
  const isAllBotsInactive = totalBotCount > 0 && activeBotCount === 0;
  const hasHandovers = handoverCount > 0;
  const hasSomeBotsInactive = activeBotCount < totalBotCount && activeBotCount > 0;

  // Status determination: RED > YELLOW > GREEN
  let status: 'green' | 'yellow' | 'red' = 'green';
  let statusText = '';
  let Icon = Activity;

  if (isNoBots) {
    status = 'yellow';
    statusText = 'ยังไม่มีบอท';
    Icon = AlertTriangle;
  } else if (isAllBotsInactive) {
    status = 'red';
    statusText = 'ออฟไลน์ · ไม่มีบอทที่ทำงาน';
    Icon = XCircle;
  } else if (hasHandovers && hasSomeBotsInactive) {
    status = 'yellow';
    statusText = `ต้องดูแล · Handover ${handoverCount} รายการ · บอทออนไลน์ ${activeBotCount}/${totalBotCount}`;
    Icon = AlertTriangle;
  } else if (hasHandovers) {
    status = 'yellow';
    statusText = `ต้องดูแล · Handover ${handoverCount} รายการ`;
    Icon = AlertTriangle;
  } else if (hasSomeBotsInactive) {
    status = 'yellow';
    statusText = `บอทออนไลน์ ${activeBotCount}/${totalBotCount}`;
    Icon = AlertTriangle;
  } else {
    status = 'green';
    statusText = `ระบบปกติ · บอทออนไลน์ ${activeBotCount}/${totalBotCount} · ไม่มี handover`;
    Icon = Activity;
  }

  // Color classes by status
  const colorClasses = {
    green:
      'bg-green-50 border-green-200 text-green-700 dark:bg-green-950/30 dark:border-green-800 dark:text-green-400',
    yellow:
      'bg-yellow-50 border-yellow-200 text-yellow-700 dark:bg-yellow-950/30 dark:border-yellow-800 dark:text-yellow-400',
    red: 'bg-red-50 border-red-200 text-red-700 dark:bg-red-950/30 dark:border-red-800 dark:text-red-400',
  };

  const dotColorClasses = {
    green: 'bg-green-500 dark:bg-green-400',
    yellow: 'bg-yellow-500 dark:bg-yellow-400',
    red: 'bg-red-500 dark:bg-red-400',
  };

  return (
    <div
      className={cn(
        'flex items-center gap-2 rounded-lg border px-4 py-2.5',
        colorClasses[status]
      )}
    >
      <span className={cn('h-2 w-2 rounded-full', dotColorClasses[status])} />
      <Icon className="h-4 w-4" />
      <span className="text-sm font-medium">{statusText}</span>
    </div>
  );
}
