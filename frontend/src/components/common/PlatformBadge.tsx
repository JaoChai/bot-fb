import type { ElementType } from 'react';
import { MessageCircle, Send, Facebook, TestTube } from 'lucide-react';
import { cn } from '@/lib/utils';

type Platform = 'line' | 'facebook' | 'telegram' | 'testing';

const CONFIG: Record<Platform, { label: string; icon: ElementType; tone: string }> = {
  line: {
    label: 'LINE',
    icon: MessageCircle,
    tone: 'text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-900',
  },
  facebook: {
    label: 'Facebook',
    icon: Facebook,
    tone: 'text-blue-700 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/40 border-blue-200 dark:border-blue-900',
  },
  telegram: {
    label: 'Telegram',
    icon: Send,
    tone: 'text-sky-700 dark:text-sky-400 bg-sky-50 dark:bg-sky-950/40 border-sky-200 dark:border-sky-900',
  },
  testing: {
    label: 'Testing',
    icon: TestTube,
    tone: 'text-muted-foreground bg-muted border-border',
  },
};

interface PlatformBadgeProps {
  platform: Platform;
  size?: 'sm' | 'md';
  showLabel?: boolean;
  className?: string;
}

export function PlatformBadge({
  platform,
  size = 'sm',
  showLabel = true,
  className,
}: PlatformBadgeProps) {
  const c = CONFIG[platform];
  const Icon = c.icon;
  const sizeClass = size === 'sm' ? 'h-5 text-xs px-1.5' : 'h-6 text-sm px-2';
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-md border font-medium',
        sizeClass,
        c.tone,
        className,
      )}
    >
      <Icon
        className={size === 'sm' ? 'h-3 w-3' : 'h-3.5 w-3.5'}
        strokeWidth={2}
      />
      {showLabel && <span>{c.label}</span>}
    </span>
  );
}

export type { Platform };
