import { cn } from '@/lib/utils';

type Status = 'active' | 'warning' | 'error' | 'inactive';

const COLORS: Record<Status, string> = {
  active: 'bg-emerald-500',
  warning: 'bg-amber-500',
  error: 'bg-destructive',
  inactive: 'bg-muted-foreground/40',
};

interface StatusDotProps {
  status: Status;
  label?: string;
  pulse?: boolean;
  className?: string;
}

export function StatusDot({ status, label, pulse = false, className }: StatusDotProps) {
  return (
    <span className={cn('inline-flex items-center gap-1.5', className)}>
      <span className="relative inline-flex">
        {pulse && status === 'active' && (
          <span
            className={cn(
              'absolute inline-flex h-2 w-2 rounded-full opacity-60 animate-ping',
              COLORS[status],
            )}
          />
        )}
        <span className={cn('inline-flex h-2 w-2 rounded-full', COLORS[status])} />
      </span>
      {label && <span className="text-xs text-muted-foreground">{label}</span>}
    </span>
  );
}

export type { Status as StatusType };
