import type { ReactNode, ElementType } from 'react';
import { cn } from '@/lib/utils';

interface SettingSectionProps {
  icon?: ElementType;
  title: string;
  description?: string;
  children: ReactNode;
  className?: string;
  action?: ReactNode;
  tone?: 'default' | 'destructive';
}

export function SettingSection({
  icon: Icon,
  title,
  description,
  children,
  className,
  action,
  tone = 'default',
}: SettingSectionProps) {
  const isDestructive = tone === 'destructive';
  return (
    <section className={cn('space-y-4', className)}>
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-3 min-w-0">
          {Icon && (
            <div
              className={cn(
                'flex-shrink-0 mt-0.5 flex h-9 w-9 items-center justify-center rounded-md border',
                isDestructive
                  ? 'border-destructive/30 bg-destructive/5 text-destructive'
                  : 'bg-muted/40 text-muted-foreground',
              )}
            >
              <Icon className="h-4 w-4" />
            </div>
          )}
          <div className="min-w-0">
            <h3
              className={cn(
                'text-sm font-semibold',
                isDestructive ? 'text-destructive' : 'text-foreground',
              )}
            >
              {title}
            </h3>
            {description && (
              <p className="text-xs text-muted-foreground mt-0.5">{description}</p>
            )}
          </div>
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>
      <div className={cn(Icon && 'sm:pl-12')}>{children}</div>
    </section>
  );
}

interface SettingRowProps {
  label: string;
  description?: string;
  htmlFor?: string;
  children: ReactNode;
  className?: string;
  orientation?: 'horizontal' | 'vertical';
}

export function SettingRow({
  label,
  description,
  htmlFor,
  children,
  className,
  orientation = 'horizontal',
}: SettingRowProps) {
  if (orientation === 'vertical') {
    return (
      <div className={cn('space-y-2', className)}>
        <div className="space-y-0.5">
          <label
            htmlFor={htmlFor}
            className="text-sm font-medium text-foreground"
          >
            {label}
          </label>
          {description && (
            <p className="text-xs text-muted-foreground">{description}</p>
          )}
        </div>
        {children}
      </div>
    );
  }

  return (
    <div
      className={cn(
        'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6',
        className
      )}
    >
      <div className="space-y-0.5 min-w-0 flex-1">
        <label
          htmlFor={htmlFor}
          className="text-sm font-medium text-foreground"
        >
          {label}
        </label>
        {description && (
          <p className="text-xs text-muted-foreground">{description}</p>
        )}
      </div>
      <div className="shrink-0">{children}</div>
    </div>
  );
}
