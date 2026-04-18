import type { ReactNode, ElementType } from 'react';
import { cn } from '@/lib/utils';

interface PanelProps {
  title?: string;
  description?: string;
  icon?: ElementType;
  actions?: ReactNode;
  children: ReactNode;
  tone?: 'default' | 'destructive' | 'secure';
  className?: string;
  bodyClassName?: string;
}

export function Panel({
  title,
  description,
  icon: Icon,
  actions,
  children,
  tone = 'default',
  className,
  bodyClassName,
}: PanelProps) {
  return (
    <section
      className={cn(
        'rounded-lg border bg-card overflow-hidden',
        tone === 'destructive' && 'border-destructive/40',
        tone === 'secure' && 'border-l-2 border-l-primary',
        className,
      )}
    >
      {(title || actions) && (
        <header className="flex items-start justify-between gap-4 border-b px-5 py-4">
          <div className="flex items-start gap-3 min-w-0">
            {Icon && (
              <div
                className={cn(
                  'flex h-7 w-7 items-center justify-center rounded-md border shrink-0',
                  tone === 'destructive' &&
                    'border-destructive/30 bg-destructive/5 text-destructive',
                  tone === 'secure' &&
                    'border-primary/20 bg-primary/5 text-primary',
                  tone === 'default' && 'bg-muted/40 text-muted-foreground',
                )}
              >
                <Icon className="h-3.5 w-3.5" strokeWidth={1.75} />
              </div>
            )}
            <div className="min-w-0">
              {title && (
                <h2
                  className={cn(
                    'text-sm font-semibold',
                    tone === 'destructive' && 'text-destructive',
                  )}
                >
                  {title}
                </h2>
              )}
              {description && (
                <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
              )}
            </div>
          </div>
          {actions && <div className="flex items-center gap-2 shrink-0">{actions}</div>}
        </header>
      )}
      <div className={cn('px-5 py-4', bodyClassName)}>{children}</div>
    </section>
  );
}
