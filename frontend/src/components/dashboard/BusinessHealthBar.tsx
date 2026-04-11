import { cn } from '@/lib/utils';
import { Activity, AlertTriangle, XCircle } from 'lucide-react';
import type { DashboardBotSummary, DashboardAlerts } from '@/types/api';

interface BusinessHealthBarProps {
  bots: DashboardBotSummary[];
  alerts: DashboardAlerts;
}

export function BusinessHealthBar({ bots, alerts }: BusinessHealthBarProps) {
  const activeBotCount = bots.filter((b) => b.status === 'active').length;
  const totalBotCount = bots.length;
  const handoverCount = alerts.handover_conversations.length;

  const isNoBots = totalBotCount === 0;
  const isAllBotsInactive = totalBotCount > 0 && activeBotCount === 0;
  const hasHandovers = handoverCount > 0;
  const hasSomeBotsInactive = activeBotCount < totalBotCount && activeBotCount > 0;

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

  const colorClasses = {
    green:
      'bg-emerald-50/80 border-emerald-200/60 text-emerald-700 dark:bg-emerald-950/30 dark:border-emerald-800/40 dark:text-emerald-400',
    yellow:
      'bg-amber-50/80 border-amber-200/60 text-amber-700 dark:bg-amber-950/30 dark:border-amber-800/40 dark:text-amber-400',
    red: 'bg-red-50/80 border-red-200/60 text-red-700 dark:bg-red-950/30 dark:border-red-800/40 dark:text-red-400',
  };

  const dotColorClasses = {
    green: 'bg-emerald-500',
    yellow: 'bg-amber-500',
    red: 'bg-red-500',
  };

  const pulseColorClasses = {
    green: 'bg-emerald-400',
    yellow: 'bg-amber-400',
    red: 'bg-red-400',
  };

  return (
    <div
      className={cn(
        'flex items-center gap-3 rounded-xl border px-4 py-3 backdrop-blur-sm',
        colorClasses[status],
      )}
    >
      {/* Animated pulse dot */}
      <span className="relative flex h-2.5 w-2.5" aria-hidden="true">
        <span
          className={cn(
            'absolute inline-flex h-full w-full animate-ping rounded-full opacity-75',
            pulseColorClasses[status],
          )}
        />
        <span
          className={cn(
            'relative inline-flex h-2.5 w-2.5 rounded-full',
            dotColorClasses[status],
          )}
        />
      </span>
      <Icon className="h-4 w-4" />
      <span className="text-sm font-medium">{statusText}</span>
    </div>
  );
}
